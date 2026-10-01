<?php

declare(strict_types=1);

namespace EidCloud\AiGateway\Providers;

/**
 * Mock Provider for high-speed deterministic testing of routing, failovers, load balancing, and token accounting.
 */
class MockProvider implements ProviderInterface
{
    private string $name;
    private bool $shouldFail;
    private string $failureMessage;
    private int $callCount = 0;
    private array $recordedPayloads = [];
    private string $mockResponseContent;

    public function __construct(string $name, bool $shouldFail = false, string $mockResponseContent = 'Mock response from AI', string $failureMessage = 'Simulated provider connection error')
    {
        $this->name = $name;
        $this->shouldFail = $shouldFail;
        $this->mockResponseContent = $mockResponseContent;
        $this->failureMessage = $failureMessage;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setShouldFail(bool $fail, string $msg = 'Simulated provider connection error'): void
    {
        $this->shouldFail = $fail;
        $this->failureMessage = $msg;
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }

    public function getRecordedPayloads(): array
    {
        return $this->recordedPayloads;
    }

    public function checkHealth(): bool
    {
        return !$this->shouldFail;
    }

    public function estimateTokens(array|string $input): int
    {
        if (is_array($input)) {
            $chars = 0;
            foreach ($input as $item) {
                $chars += strlen(is_array($item) ? ($item['content'] ?? '') : (string)$item);
            }
            return max(1, (int)ceil($chars / 4));
        }
        return max(1, (int)ceil(strlen((string)$input) / 4));
    }

    public function chatCompletion(array $payload): array
    {
        $this->callCount++;
        $this->recordedPayloads[] = $payload;

        if ($this->shouldFail) {
            throw new \RuntimeException($this->failureMessage);
        }

        $model = $payload['model'] ?? 'mock-model';
        $promptTokens = $this->estimateTokens($payload['messages'] ?? []);
        $completionTokens = $this->estimateTokens($this->mockResponseContent);

        return [
            'id' => 'chatcmpl-mock-' . bin2hex(random_bytes(6)),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $this->name . '/' . $model,
            'provider' => $this->name,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $this->mockResponseContent,
                    ],
                    'finish_reason' => 'stop',
                ]
            ],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ]
        ];
    }

    public function streamChatCompletion(array $payload, callable $onChunk): void
    {
        $res = $this->chatCompletion($payload);
        $chunk = [
            'id' => $res['id'],
            'object' => 'chat.completion.chunk',
            'choices' => [
                ['index' => 0, 'delta' => ['content' => $this->mockResponseContent], 'finish_reason' => 'stop']
            ]
        ];
        $onChunk("data: " . json_encode($chunk) . "\n\n");
        $onChunk("data: [DONE]\n\n");
    }
}
