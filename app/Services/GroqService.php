<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GroqService
{
    private string $apiKey;
    private string $baseUri;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $baseUri = null, ?string $model = null)
    {
        $this->apiKey = $apiKey ?? (string) config('services.groq.key');
        $this->baseUri = $baseUri ?? (string) config('services.groq.base_uri');
        $this->model = $model ?? (string) config('services.groq.model');
    }

    public function chat(string $prompt, array $options = []): string
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Groq API key is not configured.');
        }

        $response = Http::withToken($this->apiKey)
            ->baseUrl($this->baseUri)
            ->post('/chat/completions', [
                'model' => $options['model'] ?? $this->model,
                'messages' => $options['messages'] ?? [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Groq API request failed: '.$response->body());
        }

        return (string) $response->json('choices.0.message.content');
    }
}
