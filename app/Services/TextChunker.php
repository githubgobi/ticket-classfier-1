<?php

namespace App\Services;

class TextChunker
{
    /**
     * Split text into overlapping chunks, breaking on whitespace so words
     * aren't cut mid-token.
     *
     * @return array<int, string>
     */
    public static function chunk(string $text, int $maxChars = 800, int $overlapChars = 100): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if ($text === '') {
            return [];
        }

        if ($overlapChars >= $maxChars) {
            throw new \InvalidArgumentException('overlapChars must be smaller than maxChars.');
        }

        $chunks = [];
        $length = mb_strlen($text);
        $start = 0;

        while ($start < $length) {
            $end = min($start + $maxChars, $length);

            if ($end < $length) {
                $lastSpace = mb_strrpos(mb_substr($text, $start, $end - $start), ' ');

                if ($lastSpace !== false) {
                    $end = $start + $lastSpace;
                }
            }

            $chunk = trim(mb_substr($text, $start, $end - $start));

            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            if ($end >= $length) {
                break;
            }

            $start = max($end - $overlapChars, $start + 1);
        }

        return $chunks;
    }
}
