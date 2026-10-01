<?php

declare(strict_types=1);

namespace EidCloud\AiGateway\Providers;

/**
 * Google Gemini Provider using Google AI Studio / Gemini REST API.
 * Converts OpenAI payloads to Gemini generateContent format and back.
 */
class GeminiProvider extends AbstractProvider
{
    public function __construct(?string $apiKey = null, string $baseUrl = 'https://generativelanguage.googleapis.com', int $timeout = 30)
    {
        $apiKey = $apiKey ?? getenv('GEMINI_API_KEY') ?: null;
        parent::__construct('gemini', $baseUrl, $apiKey, $timeout);
    }

    public function checkHealth(): bool
    {
        return !empty($this->apiKey);
    }

    public function chatCompletion(array $payload): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException("Gemini API key is missing or not configured");
        }

        $model = $payload['model'] ?? 'gemini-1.5-flash';
        if (str_starts_with($model, 'gemini/')) {
            $model = substr($model, 7);
        }

        $contents = [];
        $systemInstruction = null;

        foreach ($payload['messages'] ?? [] as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';

            if ($role === 'system') {
                $systemInstruction = [
                    'parts' => [['text' => $content]]
                ];
            } else {
                $geminiRole = ($role === 'assistant') ? 'model' : 'user';
                $contents[] = [
                    'role' => $geminiRole,
                    'parts' => [['text' => $content]],
                ];
            }
        }

        $geminiPayload = ['contents' => $contents];
        if ($systemInstruction !== null) {
            $geminiPayload['systemInstruction'] = $systemInstruction;
        }

        $generationConfig = [];
        if (isset($payload['temperature'])) {
            $generationConfig['temperature'] = (float)$payload['temperature'];
        }
        if (isset($payload['max_tokens'])) {
            $generationConfig['maxOutputTokens'] = (int)$payload['max_tokens'];
        }
        if (!empty($generationConfig)) {
            $geminiPayload['generationConfig'] = $generationConfig;
        }

        $endpoint = $this->baseUrl . "/v1beta/models/{$model}:generateContent?key=" . urlencode($this->apiKey);
        $res = $this->makeHttpRequest($endpoint, 'POST', $geminiPayload);

        if ($res['code'] < 200 || $res['code'] >= 300) {
            throw new \RuntimeException("Gemini error (HTTP {$res['code']}): " . $res['body']);
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data) || empty($data['candidates'])) {
            throw new \RuntimeException("Invalid response from Gemini API: " . $res['body']);
        }

        $candidate = $data['candidates'][0] ?? [];
        $parts = $candidate['content']['parts'] ?? [];
        $responseText = '';
        foreach ($parts as $part) {
            $responseText .= $part['text'] ?? '';
        }

        $usageMeta = $data['usageMetadata'] ?? [];
        $promptTokens = $usageMeta['promptTokenCount'] ?? $this->estimateTokens($payload['messages'] ?? []);
        $completionTokens = $usageMeta['candidatesTokenCount'] ?? $this->estimateTokens($responseText);

        return [
            'id' => 'chatcmpl-' . bin2hex(random_bytes(10)),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'gemini/' . $model,
            'provider' => 'gemini',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $responseText,
                    ],
                    'finish_reason' => strtolower($candidate['finishReason'] ?? 'stop'),
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
