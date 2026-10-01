<?php

declare(strict_types=1);

namespace EidCloud\AiGateway\Accounting;

/**
 * In-memory / file-persisted thread-safe token accounting and ledger.
 */
class TokenLedger
{
    private string $storagePath;
    /** @var array<string, array{prompt_tokens: int, completion_tokens: int, total_tokens: int, total_requests: int, estimated_cost_usd: float}> */
    private array $providerUsage = [];
    /** @var array<string, array{prompt_tokens: int, completion_tokens: int, total_tokens: int, total_requests: int}> */
    private array $modelUsage = [];
    private float $budgetLimitUsd = 0.0;
    private int $totalRequests = 0;

    /**
     * Estimated cost per 1k tokens (prompt / completion) in USD.
     */
    private const DEFAULT_PRICING = [
        'openai' => ['prompt' => 0.00015, 'completion' => 0.00060], // gpt-4o-mini baseline
        'anthropic' => ['prompt' => 0.00025, 'completion' => 0.00125], // haiku baseline
        'gemini' => ['prompt' => 0.000075, 'completion' => 0.00030], // gemini-1.5-flash
        'ollama' => ['prompt' => 0.0, 'completion' => 0.0], // local / free
        'llamacpp' => ['prompt' => 0.0, 'completion' => 0.0], // local / free
    ];

    public function __construct(string $storagePath = '')
    {
        $this->storagePath = $storagePath;
        if ($storagePath !== '' && file_exists($storagePath)) {
            $data = json_decode((string)file_get_contents($storagePath), true);
            if (is_array($data)) {
                $this->providerUsage = $data['providerUsage'] ?? [];
                $this->modelUsage = $data['modelUsage'] ?? [];
                $this->totalRequests = $data['totalRequests'] ?? 0;
                $this->budgetLimitUsd = (float)($data['budgetLimitUsd'] ?? 0.0);
            }
        }
    }

    public function setBudgetLimit(float $limitUsd): void
    {
        $this->budgetLimitUsd = $limitUsd;
    }

    public function getBudgetLimit(): float
    {
        return $this->budgetLimitUsd;
    }

    public function recordUsage(string $provider, string $model, int $promptTokens, int $completionTokens): void
    {
        $totalTokens = $promptTokens + $completionTokens;
        $this->totalRequests++;

        // Provider ledger
        if (!isset($this->providerUsage[$provider])) {
            $this->providerUsage[$provider] = [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'total_requests' => 0,
                'estimated_cost_usd' => 0.0,
            ];
        }

        $cost = $this->calculateCost($provider, $promptTokens, $completionTokens);

        $this->providerUsage[$provider]['prompt_tokens'] += $promptTokens;
        $this->providerUsage[$provider]['completion_tokens'] += $completionTokens;
        $this->providerUsage[$provider]['total_tokens'] += $totalTokens;
        $this->providerUsage[$provider]['total_requests'] += 1;
        $this->providerUsage[$provider]['estimated_cost_usd'] += $cost;

        // Model ledger
        if (!isset($this->modelUsage[$model])) {
            $this->modelUsage[$model] = [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'total_requests' => 0,
            ];
        }
        $this->modelUsage[$model]['prompt_tokens'] += $promptTokens;
        $this->modelUsage[$model]['completion_tokens'] += $completionTokens;
        $this->modelUsage[$model]['total_tokens'] += $totalTokens;
        $this->modelUsage[$model]['total_requests'] += 1;

        $this->persist();
    }

    public function isBudgetExceeded(): bool
    {
        if ($this->budgetLimitUsd <= 0.0) {
            return false;
        }
        return $this->getTotalCost() >= $this->budgetLimitUsd;
    }

    public function getTotalCost(): float
    {
        $total = 0.0;
        foreach ($this->providerUsage as $usage) {
            $total += $usage['estimated_cost_usd'];
        }
        return round($total, 6);
    }

    public function getTotalTokens(): int
    {
        $total = 0;
        foreach ($this->providerUsage as $usage) {
            $total += $usage['total_tokens'];
        }
        return $total;
    }

    public function getTotalRequests(): int
    {
        return $this->totalRequests;
    }

    public function getProviderUsage(): array
    {
        return $this->providerUsage;
    }

    public function getModelUsage(): array
    {
        return $this->modelUsage;
    }

    public function getSummary(): array
    {
        return [
            'total_requests' => $this->totalRequests,
            'total_tokens' => $this->getTotalTokens(),
            'total_cost_usd' => $this->getTotalCost(),
            'budget_limit_usd' => $this->budgetLimitUsd,
            'budget_exceeded' => $this->isBudgetExceeded(),
            'providers' => $this->providerUsage,
            'models' => $this->modelUsage,
        ];
    }

    public function reset(): void
    {
        $this->providerUsage = [];
        $this->modelUsage = [];
        $this->totalRequests = 0;
        $this->persist();
    }

    private function calculateCost(string $provider, int $promptTokens, int $completionTokens): float
    {
        $pricing = self::DEFAULT_PRICING[$provider] ?? ['prompt' => 0.0, 'completion' => 0.0];
        $promptCost = ($promptTokens / 1000.0) * $pricing['prompt'];
        $completionCost = ($completionTokens / 1000.0) * $pricing['completion'];
        return round($promptCost + $completionCost, 6);
    }

    private function persist(): void
    {
        if ($this->storagePath !== '') {
            $dir = dirname($this->storagePath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            @file_put_contents($this->storagePath, json_encode([
                'providerUsage' => $this->providerUsage,
                'modelUsage' => $this->modelUsage,
                'totalRequests' => $this->totalRequests,
                'budgetLimitUsd' => $this->budgetLimitUsd,
            ], JSON_PRETTY_PRINT));
        }
    }
}
