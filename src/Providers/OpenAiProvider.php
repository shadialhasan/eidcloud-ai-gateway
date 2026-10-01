<?php

declare(strict_types=1);

namespace EidCloud\AiGateway\Providers;

/**
 * OpenAI Native Cloud Provider.
 */
class OpenAiProvider extends AbstractProvider
{
    public function __construct(?string $apiKey = null, string $baseUrl = 'https://api.openai.com', int $timeout = 30)
    {
        $apiKey = $apiKey ?? getenv('OPENAI_API_KEY') ?: null;
        parent::__construct('openai', $baseUrl, $apiKey, $timeout);
    }

    public function checkHealth(): bool
    {
        if (empty($this->apiKey)) {
            return false;
        }
        try {
            $res = $this->makeHttpRequest($this->baseUrl . '/v1/models', 'GET');
            return $res['code'] >= 200 && $res['code'] < 300;
        } catch (\Throwable) {
            return false;
        }
    }

    public function chatCompletion(array $payload): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException("OpenAI API key is missing or not configured");
        }

        $model = $payload['model'] ?? 'gpt-4o-mini';
        if (str_starts_with($model, 'openai/')) {
            $payload['model'] = substr($model, 7);
        }

        $endpoint = $this->baseUrl . '/v1/chat/completions';
        $res = $this->makeHttpRequest($endpoint, 'POST', $payload);

        if ($res['code'] < 200 || $res['code'] >= 300) {
            throw new \RuntimeException("OpenAI error (HTTP {$res['code']}): " . $res['body']);
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON response from OpenAI");
        }

        $data['provider'] = 'openai';
        return $data;
    }

    public function streamChatCompletion(array $payload, callable $onChunk): void
    {
        $payload['stream'] = false; // proxy-safe fallback simulation
        $res = $this->chatCompletion($payload);
        $content = $res['choices'][0]['message']['content'] ?? '';
        $id = $res['id'] ?? ('chatcmpl-' . bin2hex(random_bytes(10)));
        $model = $res['model'] ?? 'gpt-4o-mini';

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
