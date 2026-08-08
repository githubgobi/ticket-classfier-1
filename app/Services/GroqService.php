<?php

namespace App\Services;

use App\Exceptions\GroqConfigurationException;
use App\Exceptions\GroqException;
use App\Exceptions\GroqTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GroqService
{
    private string $apiKey;
    private string $baseUri;
    private string $model;
    private int $timeout;

    public function __construct(?string $apiKey = null, ?string $baseUri = null, ?string $model = null, ?int $timeout = null)
    {
        $this->apiKey = $apiKey ?? (string) config('services.groq.key');
        $this->baseUri = $baseUri ?? (string) config('services.groq.base_uri');
        $this->model = $model ?? (string) config('services.groq.model');
        $this->timeout = $timeout ?? (int) config('services.groq.timeout', 10);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array{model?: string, response_format?: array}  $options
     */
    public function chat(array $messages, array $options = []): string
    {
        if ($this->apiKey === '') {
            throw new GroqConfigurationException('Groq API key is not configured.');
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->baseUrl($this->baseUri)
                ->timeout($this->timeout)
                ->post('/chat/completions', [
                    'model' => $options['model'] ?? $this->model,
                    'messages' => $messages,
                    ...array_intersect_key($options, array_flip(['response_format', 'temperature'])),
                ]);
        } catch (ConnectionException $e) {
            throw new GroqTimeoutException('Groq API request timed out.', previous: $e);
        }

        if ($response->failed()) {
            throw new GroqException('Groq API request failed: '.$response->body());
        }

        return (string) $response->json('choices.0.message.content');
    }
}
