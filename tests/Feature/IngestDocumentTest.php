<?php

namespace Tests\Feature;

use App\Models\DocumentChunk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IngestDocumentTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql_rag'];

    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    private function fakeOllamaEmbeddings(): void
    {
        Http::fake([
            'localhost:11434/*' => function ($request) {
                $inputs = $request['input'];

                return Http::response([
                    'embeddings' => array_map(fn () => array_fill(0, 768, 0.1), $inputs),
                ]);
            },
        ]);
    }

    private function makeTempFile(string $contents, string $extension = 'txt'): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ingest_test_'.uniqid().'.'.$extension;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function test_it_ingests_a_text_file_into_chunks_with_embeddings(): void
    {
        $this->fakeOllamaEmbeddings();

        $path = $this->makeTempFile('Laravel is a PHP framework. Postgres is a relational database. pgvector adds vector search.');

        $this->artisan('rag:ingest', ['path' => $path, '--max-chars' => 40, '--overlap' => 5])
            ->assertExitCode(0);

        $chunks = DocumentChunk::query()->where('source', basename($path))->orderBy('chunk_index')->get();

        $this->assertGreaterThan(1, $chunks->count());
        $this->assertSame(range(0, $chunks->count() - 1), $chunks->pluck('chunk_index')->all());

        foreach ($chunks as $chunk) {
            $this->assertCount(768, $chunk->embedding->toArray());
        }
    }

    public function test_it_fails_when_the_file_does_not_exist(): void
    {
        $this->artisan('rag:ingest', ['path' => 'C:\\nonexistent\\file.txt'])
            ->assertExitCode(1);
    }

    public function test_it_fails_when_the_file_has_no_extractable_text(): void
    {
        $path = $this->makeTempFile('   ');

        $this->artisan('rag:ingest', ['path' => $path])
            ->assertExitCode(1);
    }
}
