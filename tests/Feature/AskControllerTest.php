<?php

namespace Tests\Feature;

use App\Models\DocumentChunk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AskControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql_rag'];

    private function fakeOllamaEmbedding(): void
    {
        Http::fake([
            'localhost:11434/*' => Http::response([
                'embeddings' => [array_fill(0, 768, 0.1)],
            ]),
        ]);
    }

    public function test_it_answers_using_retrieved_context(): void
    {
        DocumentChunk::create([
            'source' => 'sample-doc.txt',
            'chunk_index' => 0,
            'content' => 'The classify endpoint is rate limited to 5 requests per minute.',
            'embedding' => array_fill(0, 768, 0.1),
        ]);

        Http::fake([
            'localhost:11434/*' => Http::response(['embeddings' => [array_fill(0, 768, 0.1)]]),
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => '5 requests per minute.']]],
            ]),
        ]);

        $response = $this->postJson('/api/ask', ['question' => 'How many requests per minute is classify limited to?']);

        $response->assertOk()->assertJson([
            'answer' => '5 requests per minute.',
        ]);

        $response->assertJsonPath('sources.0.source', 'sample-doc.txt');
        $response->assertJsonPath('sources.0.chunk_index', 0);
        $response->assertJsonStructure(['sources' => [['source', 'chunk_index', 'distance']]]);
    }

    public function test_it_returns_a_fallback_message_when_no_documents_are_indexed(): void
    {
        // Don't rely on the table being empty by ambient luck — other real
        // documents may already be ingested in this database.
        DocumentChunk::query()->delete();

        Http::preventStrayRequests();
        $this->fakeOllamaEmbedding();

        $response = $this->postJson('/api/ask', ['question' => 'Anything at all?']);

        $response->assertOk()->assertJson([
            'answer' => "I don't have enough information to answer that.",
            'sources' => [],
        ]);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.groq.com'));
    }

    public function test_it_requires_a_question(): void
    {
        $response = $this->postJson('/api/ask', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['question']);
    }

    public function test_it_returns_502_when_the_embedding_service_fails(): void
    {
        Http::fake([
            'localhost:11434/*' => Http::response(['error' => 'model not found'], 404),
        ]);

        $response = $this->postJson('/api/ask', ['question' => 'Does this work?']);

        $response->assertStatus(502)->assertJson([
            'error' => 'The embedding service is unavailable. Please try again later.',
        ]);
    }

    public function test_it_returns_502_when_groq_fails(): void
    {
        DocumentChunk::create([
            'source' => 'sample-doc.txt',
            'chunk_index' => 0,
            'content' => 'Some content.',
            'embedding' => array_fill(0, 768, 0.1),
        ]);

        Http::fake([
            'localhost:11434/*' => Http::response(['embeddings' => [array_fill(0, 768, 0.1)]]),
            'api.groq.com/*' => Http::response(['error' => 'bad request'], 400),
        ]);

        $response = $this->postJson('/api/ask', ['question' => 'Does this work?']);

        $response->assertStatus(502)->assertJson([
            'error' => 'The answer generation service is unavailable. Please try again later.',
        ]);
    }
}
