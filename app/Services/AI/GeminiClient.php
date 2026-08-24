<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {}

    public static function fromConfig(): self
    {
        $key = (string) config('services.gemini.api_key');
        if ($key === '') {
            throw new AiException('GEMINI_API_KEY not set', 'gemini');
        }

        return new self(
            apiKey: $key,
            model: (string) config('services.gemini.model'),
            baseUrl: rtrim((string) config('services.gemini.base_url'), '/'),
            timeout: (int) config('services.gemini.timeout'),
        );
    }

    /**
     * Send a single-turn prompt. Returns generated text.
     *
     * @param  array<int, array{role: 'user'|'model', text: string}>  $history  optional prior turns
     */
    public function generate(string $prompt, array $history = [], ?string $systemInstruction = null): string
    {
        $contents = [];
        foreach ($history as $turn) {
            $contents[] = ['role' => $turn['role'], 'parts' => [['text' => $turn['text']]]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $prompt]]];

        $body = ['contents' => $contents];
        if ($systemInstruction) {
            $body['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
        }

        $url = "{$this->baseUrl}/models/{$this->model}:generateContent";

        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'x-goog-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post($url, $body);

        if ($response->failed()) {
            Log::warning('gemini.request_failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new AiException(
                'Gemini API error: '.($response->json('error.message') ?? $response->status()),
                'gemini',
                $response->status(),
                $response->json(),
            );
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        if (! is_string($text) || $text === '') {
            $finish = $response->json('candidates.0.finishReason');
            throw new AiException(
                "Gemini returned no text (finishReason={$finish})",
                'gemini',
                $response->status(),
                $response->json(),
            );
        }

        return $text;
    }
}
