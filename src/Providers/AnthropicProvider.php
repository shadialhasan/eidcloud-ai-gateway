<?php

declare(strict_types=1);

namespace EidCloud\AiGateway\Providers;

/**
 * Anthropic Messages API Provider.
 * Converts OpenAI chat payloads to Anthropic Messages format and back.
 */
class AnthropicProvider extends AbstractProvider
{
    private string $anthropicVersion;

    public function __construct(?string $apiKey = null, string $baseUrl = 'https://api.anthropic.com', string $version = '2023-06-01', int $timeout = 30)
    {
        $apiKey = $apiKey ?? getenv('ANTHROPIC_API_KEY') ?: null;
        parent::__construct('anthropic', $baseUrl, $apiKey, $timeout);
        $this->anthropicVersion = $version;
    }

    public function checkHealth(): bool
    {
        // Anthropic requires API key
        return !empty($this->apiKey);
    }

    public function chatCompletion(array $payload): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException("Anthropic API key is missing or not configured");
        }

        $model = $payload['model'] ?? 'claude-3-5-haiku-20241022';
        if (str_starts_with($model, 'anthropic/')) {
            $model = substr($model, 10);
        }

        // Map messages & system message
        $system = '';
        $messages = [];
        foreach ($payload['messages'] ?? [] as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';
            if ($role === 'system') {
                $system = $content;
            } else {
                $messages[] = [
                    'role' => ($role === 'assistant') ? 'assistant' : 'user',
                    'content' => $content,
                ];
            }
        }

        $maxTokens = (int)($payload['max_tokens'] ?? 2048);
        $anthropicPayload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
        ];

        if ($system !== '') {
            $anthropicPayload['system'] = $system;
        }
        if (isset($payload['temperature'])) {
            $anthropicPayload['temperature'] = (float)$payload['temperature'];
        }

        $headers = [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . $this->anthropicVersion,
        ];

        $endpoint = $this->baseUrl . '/v1/messages';
        $res = $this->makeHttpRequest($endpoint, 'POST', $anthropicPayload, $headers);

        if ($res['code'] < 200 || $res['code'] >= 300) {
            throw new \RuntimeException("Anthropic error (HTTP {$res['code']}): " . $res['body']);
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data) || !isset($data['content'])) {
            throw new \RuntimeException("Invalid JSON response from Anthropic");
        }

        $responseText = '';
        foreach ($data['content'] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $responseText .= $block['text'];
            }
        }

        $inputTokens = $data['usage']['input_tokens'] ?? $this->estimateTokens($payload['messages'] ?? []);
        $outputTokens = $data['usage']['output_tokens'] ?? $this->estimateTokens($responseText);

        return [
            'id' => $data['id'] ?? ('chatcmpl-' . bin2hex(random_bytes(10))),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'anthropic/' . $model,
            'provider' => 'anthropic',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $responseText,
                    ],
                    'finish_reason' => ($data['stop_reason'] === 'end_turn') ? 'stop' : ($data['stop_reason'] ?? 'stop'),
                ]
            ],
            'usage' => [
                'prompt_tokens' => (int)$inputTokens,
                'completion_tokens' => (int)$outputTokens,
                'total_tokens' => (int)($inputTokens + $outputTokens),
            ]
        ];
    }

    public function streamChatCompletion(array $payload, callable $onChunk): void
    {
        $res = $this->chatCompletion($payload);
        $content = $res['choices'][0]['message']['content'] ?? '';
        $id = $res['id'];
        $model = $res['model'];

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
            'usage' => $res['usage']
        ];
        $onChunk("data: " . json_encode($chunkEnd) . "\n\n");
        $onChunk("data: [DONE]\n\n");
    }
}
