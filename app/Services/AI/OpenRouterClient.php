<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
    ) {}

    public static function fromConfig(): self
    {
        $key = (string) config('services.openrouter.api_key');
        if ($key === '') {
            throw new AiException('OPENROUTER_API_KEY not set', 'openrouter');
        }

        return new self(
            apiKey: $key,
            model: (string) config('services.openrouter.model'),
            baseUrl: rtrim((string) config('services.openrouter.base_url'), '/'),
        );
    }

    /**
     * OpenAI-compatible chat completion. Returns assistant message text.
     *
     * @param  array<int, array{role: 'system'|'user'|'assistant', content: string}>  $messages
     */
    public function chat(array $messages, float $temperature = 0.4, ?int $maxTokens = null): string
    {
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
        ];
        if ($maxTokens !== null) {
            $body['max_tokens'] = $maxTokens;
        }

        $response = Http::timeout(45)
            ->withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
                // Optional but recommended by OpenRouter for analytics/free-tier ranking:
                'HTTP-Referer' => (string) config('app.url'),
                'X-Title' => (string) config('app.name'),
            ])
            ->post("{$this->baseUrl}/chat/completions", $body);

        if ($response->failed()) {
            Log::warning('openrouter.request_failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new AiException(
                'OpenRouter API error: '.($response->json('error.message') ?? $response->status()),
                'openrouter',
                $response->status(),
                $response->json(),
            );
        }

        $text = $response->json('choices.0.message.content');
        if (! is_string($text) || $text === '') {
            throw new AiException('OpenRouter returned empty response', 'openrouter', $response->status(), $response->json());
        }

        return $text;
    }
}
