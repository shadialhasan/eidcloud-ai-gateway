<?php

declare(strict_types=1);

namespace EidCloud\AiGateway\Providers;

/**
 * llama.cpp Native Server Provider (supports /completion or /v1/chat/completions).
 */
class LlamaCppProvider extends AbstractProvider
{
    public function __construct(string $baseUrl = 'http://127.0.0.1:8080', ?string $apiKey = null, int $timeout = 30)
    {
        parent::__construct('llamacpp', $baseUrl, $apiKey, $timeout);
    }

    public function checkHealth(): bool
    {
        try {
            $res = $this->makeHttpRequest($this->baseUrl . '/health', 'GET');
            return $res['code'] >= 200 && $res['code'] < 300;
        } catch (\Throwable) {
            return false;
        }
    }

    public function chatCompletion(array $payload): array
    {
        $model = $payload['model'] ?? 'llama-3';
        if (str_starts_with($model, 'llamacpp/')) {
            $model = substr($model, 9);
        }

        $endpoint = $this->baseUrl . '/v1/chat/completions';
        $res = $this->makeHttpRequest($endpoint, 'POST', $payload);

        if ($res['code'] < 200 || $res['code'] >= 300) {
            throw new \RuntimeException("llama.cpp error (HTTP {$res['code']}): " . $res['body']);
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data) || !isset($data['choices'])) {
            throw new \RuntimeException("Invalid response structure from llama.cpp");
        }

        $data['provider'] = 'llamacpp';
        return $data;
    }

    public function streamChatCompletion(array $payload, callable $onChunk): void
    {
        $res = $this->chatCompletion($payload);
        $content = $res['choices'][0]['message']['content'] ?? '';
        $id = $res['id'] ?? ('chatcmpl-' . bin2hex(random_bytes(10)));
        $model = $res['model'] ?? 'llamacpp';

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

        $chunkEnd = [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $model,
            'choices' => [
                ['index' => 0, 'delta' => new \stdClass(), 'finish_reason' => 'stop']
            ],
            'usage' => $res['usage'] ?? []
        ];
        $onChunk("data: " . json_encode($chunkEnd) . "\n\n");
        $onChunk("data: [DONE]\n\n");
    }
}
