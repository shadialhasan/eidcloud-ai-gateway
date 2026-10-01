<?php

declare(strict_types=1);

namespace EidCloud\AiGateway;

use EidCloud\AiGateway\Accounting\TokenLedger;
use EidCloud\AiGateway\Health\HealthMonitor;
use EidCloud\AiGateway\Providers\AnthropicProvider;
use EidCloud\AiGateway\Providers\GeminiProvider;
use EidCloud\AiGateway\Providers\LlamaCppProvider;
use EidCloud\AiGateway\Providers\OllamaProvider;
use EidCloud\AiGateway\Providers\OpenAiProvider;
use EidCloud\AiGateway\Providers\ProviderInterface;
use EidCloud\AiGateway\Router\ProviderRouter;

class Gateway
{
    public const VERSION = '1.0.0';

    private ProviderRouter $router;
    private TokenLedger $ledger;
    private HealthMonitor $healthMonitor;
    private array $config;

    public function __construct(array $config = [])
    {
        $defaultLedger = sys_get_temp_dir() . '/eidcloud_gateway_ledger_' . uniqid() . '.json';
        $this->config = array_merge([
            'ledger_file' => $defaultLedger,
            'budget_limit_usd' => 0.0,
            'default_strategy' => 'failover', // 'failover' | 'round-robin' | 'weighted'
        ], $config);

        $this->router = new ProviderRouter();
        $this->ledger = new TokenLedger($this->config['ledger_file']);
        if ($this->config['budget_limit_usd'] > 0.0) {
            $this->ledger->setBudgetLimit((float)$this->config['budget_limit_usd']);
        }
        $this->healthMonitor = new HealthMonitor();
    }

    /**
     * Bootstrap default providers (Ollama, llama.cpp, OpenAI, Anthropic, Gemini) from environment or options.
     */
    public static function createDefault(array $config = []): self
    {
        $gateway = new self($config);

        // Register default providers
        $ollamaUrl = getenv('OLLAMA_BASE_URL') ?: 'http://127.0.0.1:11434';
        $llamaUrl = getenv('LLAMACPP_BASE_URL') ?: 'http://127.0.0.1:8080';

        $gateway->registerProvider(new OllamaProvider($ollamaUrl), 5);
        $gateway->registerProvider(new LlamaCppProvider($llamaUrl), 3);
        $gateway->registerProvider(new OpenAiProvider(), 1);
        $gateway->registerProvider(new AnthropicProvider(), 1);
        $gateway->registerProvider(new GeminiProvider(), 1);

        // Standard default aliases
        $gateway->setAlias('coder', ['ollama/qwen2.5-coder', 'openai/gpt-4o-mini', 'anthropic/claude-3-5-haiku-20241022']);
        $gateway->setAlias('fast', ['ollama/llama3.2', 'gemini/gemini-1.5-flash', 'openai/gpt-4o-mini']);
        $gateway->setAlias('general', ['ollama/llama3.2', 'openai/gpt-4o-mini', 'anthropic/claude-3-5-haiku-20241022']);

        return $gateway;
    }

    public function registerProvider(ProviderInterface $provider, int $weight = 1): self
    {
        $this->router->registerProvider($provider, $weight);
        $this->healthMonitor->addProvider($provider);
        return $this;
    }

    public function setAlias(string $alias, array $targets): self
    {
        $this->router->setAlias($alias, $targets);
        return $this;
    }

    public function getRouter(): ProviderRouter
    {
        return $this->router;
    }

    public function getLedger(): TokenLedger
    {
        return $this->ledger;
    }

    public function getHealthMonitor(): HealthMonitor
    {
        return $this->healthMonitor;
    }

    /**
     * Process an OpenAI-compatible /v1/chat/completions payload with automatic failover and accounting.
     *
     * @param array $payload
     * @param string|null $strategy
     * @return array OpenAI-compatible JSON response
     * @throws \RuntimeException
     */
    public function handleChatCompletion(array $payload, ?string $strategy = null): array
    {
        if ($this->ledger->isBudgetExceeded()) {
            throw new \RuntimeException("Budget limit reached ({$this->ledger->getBudgetLimit()} USD). Request rejected by EidCloud AI Gateway.");
        }

        $strategy = $strategy ?? $this->config['default_strategy'];
        $requestedModel = $payload['model'] ?? 'general';

        $candidates = $this->router->resolveCandidates($requestedModel, $strategy);
        if (empty($candidates)) {
            throw new \RuntimeException("No available providers configured for model/alias: {$requestedModel}");
        }

        $lastException = null;
        $attemptErrors = [];

        foreach ($candidates as $cand) {
            /** @var ProviderInterface $provider */
            $provider = $cand['provider'];
            $targetModel = $cand['model'];

            // Clone payload with provider-specific resolved model
            $execPayload = $payload;
            $execPayload['model'] = $targetModel;

            try {
                $response = $provider->chatCompletion($execPayload);

                // Add gateway routing metadata
                $response['gateway'] = [
                    'version' => self::VERSION,
                    'provider' => $provider->getName(),
                    'resolved_model' => $targetModel,
                    'original_model' => $requestedModel,
                    'strategy' => $strategy,
                ];

                // Record accounting
                $promptTokens = (int)($response['usage']['prompt_tokens'] ?? $provider->estimateTokens($payload['messages'] ?? []));
                $completionTokens = (int)($response['usage']['completion_tokens'] ?? 0);
                $this->ledger->recordUsage($provider->getName(), $targetModel, $promptTokens, $completionTokens);

                return $response;
            } catch (\Throwable $e) {
                $lastException = $e;
                $attemptErrors[] = "[{$provider->getName()}/{$targetModel}]: " . $e->getMessage();
                // Failover to next candidate in list
                continue;
            }
        }

        $allErrors = implode(' | ', $attemptErrors);
        throw new \RuntimeException("All failover targets exhausted for '{$requestedModel}'. Failures: {$allErrors}", 0, $lastException);
    }

    /**
     * Handle streaming chat completion with failover to next provider if connection fails before chunking.
     *
     * @param array $payload
     * @param callable(string $chunk): void $onChunk
     * @param string|null $strategy
     */
    public function handleStreamChatCompletion(array $payload, callable $onChunk, ?string $strategy = null): void
    {
        if ($this->ledger->isBudgetExceeded()) {
            throw new \RuntimeException("Budget limit reached ({$this->ledger->getBudgetLimit()} USD). Request rejected by EidCloud AI Gateway.");
        }

        $strategy = $strategy ?? $this->config['default_strategy'];
        $requestedModel = $payload['model'] ?? 'general';

        $candidates = $this->router->resolveCandidates($requestedModel, $strategy);
        if (empty($candidates)) {
            throw new \RuntimeException("No available providers configured for model/alias: {$requestedModel}");
        }

        $lastException = null;
        $attemptErrors = [];

        foreach ($candidates as $cand) {
            /** @var ProviderInterface $provider */
            $provider = $cand['provider'];
            $targetModel = $cand['model'];

            $execPayload = $payload;
            $execPayload['model'] = $targetModel;

            try {
                $provider->streamChatCompletion($execPayload, $onChunk);

                // Approximate accounting for streaming
                $promptTokens = $provider->estimateTokens($payload['messages'] ?? []);
                $this->ledger->recordUsage($provider->getName(), $targetModel, $promptTokens, 50);
                return;
            } catch (\Throwable $e) {
                $lastException = $e;
                $attemptErrors[] = "[{$provider->getName()}/{$targetModel}]: " . $e->getMessage();
                continue;
            }
        }

        $allErrors = implode(' | ', $attemptErrors);
        throw new \RuntimeException("Streaming failover targets exhausted for '{$requestedModel}'. Failures: {$allErrors}", 0, $lastException);
    }
}
