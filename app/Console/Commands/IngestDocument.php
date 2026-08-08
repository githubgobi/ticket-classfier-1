<?php

namespace App\Console\Commands;

use App\Models\DocumentChunk;
use App\Services\OllamaEmbeddingService;
use App\Services\TextChunker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Smalot\PdfParser\Parser as PdfParser;

#[Signature('rag:ingest {path : Path to a .txt, .md, or .pdf file} {--max-chars=800} {--overlap=100}')]
#[Description('Ingest a document into the RAG store: extract text, chunk it, embed each chunk via Ollama, and save it.')]
class IngestDocument extends Command
{
    public function __construct(private readonly OllamaEmbeddingService $embeddings)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $text = $this->extractText($path);

        if (trim($text) === '') {
            $this->error('No text could be extracted from the file.');

            return self::FAILURE;
        }

        $chunks = TextChunker::chunk($text, (int) $this->option('max-chars'), (int) $this->option('overlap'));

        if ($chunks === []) {
            $this->error('Text extracted but produced no chunks.');

            return self::FAILURE;
        }

        $source = basename($path);

        $this->info(sprintf('Ingesting %d chunks from %s...', count($chunks), $source));

        $bar = $this->output->createProgressBar(count($chunks));
        $bar->start();

        foreach (array_chunk($chunks, 16, preserve_keys: true) as $batch) {
            $vectors = $this->embeddings->embed(array_values($batch));

            foreach (array_values($batch) as $i => $chunk) {
                DocumentChunk::create([
                    'source' => $source,
                    'chunk_index' => array_keys($batch)[$i],
                    'content' => $chunk,
                    'embedding' => $vectors[$i],
                ]);

                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }

    private function extractText(string $path): string
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf') {
            return (new PdfParser)->parseFile($path)->getText();
        }

        return (string) file_get_contents($path);
    }
}
