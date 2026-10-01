<?php

declare(strict_types=1);

namespace EidCloud\AiGateway\Providers;

interface ProviderInterface
{
    /**
     * Get the unique name of the provider (e.g., 'ollama', 'openai', 'anthropic', 'gemini', 'llamacpp').
     */
    public function getName(): string;

    /**
     * Check if the provider service is healthy and reachable.
     */
    public function checkHealth(): bool;

    /**
     * Complete a chat request in OpenAI-compatible format.
     *
     * @param array $payload OpenAI-compatible request payload (messages, model, temperature, max_tokens, stream, etc.)
     * @return array Standardized OpenAI-compatible response array
     * @throws \RuntimeException If the provider request fails or returns an error
     */
    public function chatCompletion(array $payload): array;

    /**
     * Stream a chat completion in Server-Sent Events (SSE) format to a callable or output buffer.
     *
     * @param array $payload OpenAI-compatible request payload
     * @param callable(string $chunk): void $onChunk Callback invoked for each SSE formatted chunk
     * @return void
     * @throws \RuntimeException If streaming fails
     */
    public function streamChatCompletion(array $payload, callable $onChunk): void;

    /**
     * Estimate token count from string or messages for accounting when API doesn't provide exact usage.
     *
     * @param array|string $input
     * @return int
     */
    public function estimateTokens(array|string $input): int;
}
