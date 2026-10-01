<?php

declare(strict_types=1);

namespace EidCloud\AiGateway\Providers;

/**
 * Ollama Native Provider integration.
 * Supports /api/chat or OpenAI compatibility layer /v1/chat/completions.
 */
class OllamaProvider extends AbstractProvider
{
    public function __construct(string $baseUrl = 'http://127.0.0.1:11434', ?string $apiKey = null, int $timeout = 30)
    {
        parent::__construct('ollama', $baseUrl, $apiKey, $timeout);
    }

    public function checkHealth(): bool
    {
        try {
            $res = $this->makeHttpRequest($this->baseUrl . '/api/tags', 'GET');
            return $res['code'] >= 200 && $res['code'] < 300;
        } catch (\Throwable) {
            return false;
        }
    }

    public function chatCompletion(array $payload): array
    {
        $model = $payload['model'] ?? 'llama3.2';
        // Strip provider prefix if present (e.g. ollama/llama3.2 -> llama3.2)
        if (str_starts_with($model, 'ollama/')) {
            $model = substr($model, 7);
        }

        $ollamaPayload = [
            'model' => $model,
            'messages' => $payload['messages'] ?? [],
            'stream' => false,
            'options' => [],
        ];

        if (isset($payload['temperature'])) {
            $ollamaPayload['options']['temperature'] = (float)$payload['temperature'];
        }
        if (isset($payload['max_tokens'])) {
            $ollamaPayload['options']['num_predict'] = (int)$payload['max_tokens'];
        }

        $res = $this->makeHttpRequest($this->baseUrl . '/api/chat', 'POST', $ollamaPayload);

        if ($res['code'] < 200 || $res['code'] >= 300) {
            throw new \RuntimeException("Ollama error (HTTP {$res['code']}): " . $res['body']);
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON response from Ollama");
        }

        $content = $data['message']['content'] ?? '';
        $promptTokens = $data['prompt_eval_count'] ?? $this->estimateTokens($payload['messages'] ?? []);
        $completionTokens = $data['eval_count'] ?? $this->estimateTokens($content);

        return [
            'id' => 'chatcmpl-' . bin2hex(random_bytes(12)),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'ollama/' . $model,
            'provider' => 'ollama',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                    'finish_reason' => $data['done_reason'] ?? 'stop',
                ]
            ],
            'usage' => [
                'prompt_tokens' => (int)$promptTokens,
                'completion_tokens' => (int)$completionTokens,
                'total_tokens' => (int)($promptTokens + $completionTokens),
            ]
        ];
    }

    public function streamChatCompletion(array $payload, callable $onChunk): void
    {
        $response = $this->chatCompletion($payload);
        $content = $response['choices'][0]['message']['content'] ?? '';
        $id = $response['id'];
        $model = $response['model'];

        // Initial chunk (role)
        $chunk1 = [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $model,
            'choices' => [
                ['index' => 0, 'delta' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => null]
            ]
        ];
        $onChunk("data: " . json_encode($chunk1) . "\n\n");

        // Split words/tokens for realistic streaming simulation
        $parts = preg_split('/(\s+)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if ($part === '') continue;
                $chunkPart = [
                    'id' => $id,
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => $model,
                    'choices' => [
                        ['index' => 0, 'delta' => ['content' => $part], 'finish_reason' => null]
                    ]
                ];
                $onChunk("data: " . json_encode($chunkPart) . "\n\n");
            }
        }

        // Final chunk
        $chunkEnd = [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $model,
            'choices' => [
                ['index' => 0, 'delta' => new \stdClass(), 'finish_reason' => 'stop']
            ],
            'usage' => $response['usage']
        ];
        $onChunk("data: " . json_encode($chunkEnd) . "\n\n");
        $onChunk("data: [DONE]\n\n");
    }
}
