<?php

namespace Tests\Unit;

use App\Exceptions\OllamaException;
use App\Services\OllamaEmbeddingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OllamaEmbeddingServiceTest extends TestCase
{
    public function test_it_returns_embeddings_for_each_input(): void
    {
        Http::fake([
            'localhost:11434/*' => Http::response([
                'model' => 'nomic-embed-text',
                'embeddings' => [
                    [0.1, 0.2, 0.3],
                    [0.4, 0.5, 0.6],
                ],
            ], 200),
        ]);

        $service = new OllamaEmbeddingService(baseUri: 'http://localhost:11434', model: 'nomic-embed-text');

        $result = $service->embed(['first chunk', 'second chunk']);

        $this->assertSame([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]], $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:11434/api/embed'
                && $request['model'] === 'nomic-embed-text'
                && $request['input'] === ['first chunk', 'second chunk'];
        });
    }

    public function test_embed_one_returns_a_single_vector(): void
    {
        Http::fake([
            'localhost:11434/*' => Http::response([
                'embeddings' => [[0.1, 0.2, 0.3]],
            ], 200),
        ]);

        $service = new OllamaEmbeddingService(baseUri: 'http://localhost:11434', model: 'nomic-embed-text');

        $this->assertSame([0.1, 0.2, 0.3], $service->embedOne('a chunk'));
    }

    public function test_it_throws_when_the_request_fails(): void
    {
        Http::fake([
            'localhost:11434/*' => Http::response(['error' => 'model not found'], 404),
        ]);

        $service = new OllamaEmbeddingService(baseUri: 'http://localhost:11434', model: 'nomic-embed-text');

        $this->expectException(OllamaException::class);

        $service->embed(['a chunk']);
    }

    public function test_it_throws_when_the_embeddings_count_does_not_match_the_input_count(): void
    {
        Http::fake([
            'localhost:11434/*' => Http::response([
                'embeddings' => [[0.1, 0.2, 0.3]],
            ], 200),
        ]);

        $service = new OllamaEmbeddingService(baseUri: 'http://localhost:11434', model: 'nomic-embed-text');

        $this->expectException(OllamaException::class);

        $service->embed(['first chunk', 'second chunk']);
    }
}
