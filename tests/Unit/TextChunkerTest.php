<?php

namespace Tests\Unit;

use App\Services\TextChunker;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TextChunkerTest extends TestCase
{
    public function test_it_returns_an_empty_array_for_blank_text(): void
    {
        $this->assertSame([], TextChunker::chunk('   '));
        $this->assertSame([], TextChunker::chunk(''));
    }

    public function test_it_returns_a_single_chunk_when_text_fits(): void
    {
        $chunks = TextChunker::chunk('Short piece of text.', 800, 100);

        $this->assertSame(['Short piece of text.'], $chunks);
    }

    public function test_it_splits_long_text_into_overlapping_chunks(): void
    {
        $text = str_repeat('The quick brown fox jumps over the lazy dog. ', 20);

        $chunks = TextChunker::chunk($text, 200, 40);

        $this->assertGreaterThan(1, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(200, strlen($chunk));
        }

        // Consecutive chunks should overlap: the tail of one chunk reappears
        // near the head of the next.
        $tailOfFirst = substr($chunks[0], -20);
        $this->assertStringContainsString(trim($tailOfFirst), $chunks[0].' '.$chunks[1]);
    }

    public function test_it_does_not_lose_or_duplicate_the_full_text_span(): void
    {
        $text = 'one two three four five six seven eight nine ten';

        $chunks = TextChunker::chunk($text, 15, 3);

        // Every word from the source text shows up in at least one chunk.
        foreach (explode(' ', $text) as $word) {
            $this->assertTrue(
                collect($chunks)->contains(fn ($chunk) => str_contains($chunk, $word)),
                "Expected '{$word}' to appear in some chunk."
            );
        }
    }

    public function test_it_rejects_overlap_greater_than_or_equal_to_max_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TextChunker::chunk('some text', 100, 100);
    }
}
