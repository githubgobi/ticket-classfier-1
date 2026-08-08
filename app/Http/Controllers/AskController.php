<?php

namespace App\Http\Controllers;

use App\Exceptions\GroqException;
use App\Exceptions\OllamaException;
use App\Models\DocumentChunk;
use App\Services\GroqService;
use App\Services\OllamaEmbeddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pgvector\Laravel\Distance;

class AskController extends Controller
{
    private const TOP_K = 4;

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are a documentation assistant. Answer the user's question using
        ONLY the provided context chunks. If the context does not contain
        the answer, say "I don't have enough information to answer that."
        instead of guessing. Be concise.
        PROMPT;

    public function __construct(
        private readonly OllamaEmbeddingService $embeddings,
        private readonly GroqService $groq,
    ) {
    }

    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $questionVector = $this->embeddings->embedOne($validated['question']);
        } catch (OllamaException $e) {
            return response()->json(['error' => 'The embedding service is unavailable. Please try again later.'], 502);
        }

        $matches = DocumentChunk::query()
            ->nearestNeighbors('embedding', $questionVector, Distance::Cosine)
            ->limit(self::TOP_K)
            ->get();

        if ($matches->isEmpty()) {
            return response()->json([
                'answer' => "I don't have enough information to answer that.",
                'sources' => [],
            ]);
        }

        $context = $matches
            ->map(fn (DocumentChunk $chunk, int $i) => sprintf('[%d] (%s)%s%s', $i + 1, $chunk->source, "\n", $chunk->content))
            ->implode("\n\n");

        $userPrompt = "Context:\n{$context}\n\nQuestion: {$validated['question']}";

        try {
            $answer = $this->groq->chat([
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $userPrompt],
            ]);
        } catch (GroqException $e) {
            return response()->json(['error' => 'The answer generation service is unavailable. Please try again later.'], 502);
        }

        return response()->json([
            'answer' => trim($answer),
            'sources' => $matches->map(fn (DocumentChunk $chunk) => [
                'source' => $chunk->source,
                'chunk_index' => $chunk->chunk_index,
                'distance' => round($chunk->neighbor_distance, 4),
            ])->values(),
        ]);
    }
}
