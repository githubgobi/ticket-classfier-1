<?php

namespace App\Services;

class TicketClassifierService
{
    public const CATEGORIES = ['bug', 'feature-request', 'documentation', 'other'];

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are a support ticket classifier. Classify the ticket into exactly
        one of these categories: bug, feature-request, documentation, other.

        - bug: something is broken or behaving incorrectly.
        - feature-request: a request for new functionality or an enhancement.
        - documentation: docs are missing, unclear, or incorrect.
        - other: anything that does not fit the categories above.

        Examples:

        Title: App crashes on login
        Description: Tapping "Sign in" closes the app immediately on iOS 17.
        Output: {"category": "bug", "confidence": 0.97, "reasoning": "Describes a crash, a concrete broken behavior."}

        Title: Add dark mode
        Description: Would love a dark theme option in settings.
        Output: {"category": "feature-request", "confidence": 0.95, "reasoning": "Requests new functionality that does not exist yet."}

        Title: README setup steps are wrong
        Description: The install command in the README fails on a fresh clone.
        Output: {"category": "documentation", "confidence": 0.9, "reasoning": "Issue is with incorrect documentation content."}

        Title: General question
        Description: Do you offer student discounts?
        Output: {"category": "other", "confidence": 0.8, "reasoning": "A general inquiry, not a bug, feature, or docs issue."}

        Respond with ONLY a JSON object in exactly this shape, no extra text:
        {"category": "bug|feature-request|documentation|other", "confidence": 0.0-1.0, "reasoning": "one short sentence"}
        PROMPT;

    public function __construct(private readonly GroqService $groq)
    {
    }

    /**
     * @return array{category: string, confidence: float, reasoning: string}
     */
    public function classify(string $title, string $description): array
    {
        $content = $this->groq->chat([
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => "Title: {$title}\nDescription: {$description}"],
        ], [
            'response_format' => ['type' => 'json_object'],
        ]);

        $result = json_decode($content, true);

        $category = is_array($result) && is_string($result['category'] ?? null)
            ? strtolower(trim($result['category']))
            : null;

        if (! in_array($category, self::CATEGORIES, true)) {
            $category = 'other';
        }

        $confidence = is_array($result) && is_numeric($result['confidence'] ?? null)
            ? max(0.0, min(1.0, (float) $result['confidence']))
            : 0.5;

        $reasoning = is_array($result) && is_string($result['reasoning'] ?? null)
            ? $result['reasoning']
            : 'No reasoning provided.';

        return compact('category', 'confidence', 'reasoning');
    }
}
