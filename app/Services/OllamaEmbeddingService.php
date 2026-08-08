<?php

namespace App\Services;

use App\Exceptions\OllamaException;
use Illuminate\Support\Facades\Http;

class OllamaEmbeddingService
{
    private string $baseUri;
    private string $model;

    public function __construct(?string $baseUri = null, ?string $model = null)
    {
        $this->baseUri = $baseUri ?? (string) config('services.ollama.base_uri');
        $this->model = $model ?? (string) config('services.ollama.embed_model');
    }

    /**
     * @param  array<int, string>  $inputs
     * @return array<int, array<int, float>>
     */
    public function embed(array $inputs): array
    {
        $response = Http::baseUrl($this->baseUri)->post('/api/embed', [
            'model' => $this->model,
            'input' => $inputs,
        ]);

        if ($response->failed()) {
            throw new OllamaException('Ollama embedding request failed: '.$response->body());
        }

        $embeddings = $response->json('embeddings');

        if (! is_array($embeddings) || count($embeddings) !== count($inputs)) {
            throw new OllamaException('Ollama embedding response was malformed.');
        }

        return $embeddings;
    }

    /**
     * @return array<int, float>
     */
    public function embedOne(string $input): array
    {
        return $this->embed([$input])[0];
    }
}
