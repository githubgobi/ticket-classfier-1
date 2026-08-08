<?php

namespace App\Http\Controllers;

use App\Exceptions\GroqConfigurationException;
use App\Exceptions\GroqException;
use App\Exceptions\GroqTimeoutException;
use App\Services\GroqService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TicketController extends Controller
{
    private const CATEGORIES = ['bug', 'feature-request', 'documentation', 'other'];

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

    public function classify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
        ]);

        $userPrompt = "Title: {$validated['title']}\nDescription: {$validated['description']}";

        $startedAt = microtime(true);

        try {
            $content = $this->groq->chat([
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $userPrompt],
            ], [
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (GroqConfigurationException $e) {
            $this->logFailure($validated['title'], $startedAt, $e);

            return response()->json(['error' => 'Classification service is not configured.'], 500);
        } catch (GroqTimeoutException $e) {
            $this->logFailure($validated['title'], $startedAt, $e);

            return response()->json(['error' => 'The classification service timed out. Please try again.'], 504);
        } catch (GroqException $e) {
            $this->logFailure($validated['title'], $startedAt, $e);

            return response()->json(['error' => 'The classification service is unavailable. Please try again later.'], 502);
        }

        $durationMs = $this->elapsedMs($startedAt);
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

        Log::info('Ticket classified', [
            'title' => $validated['title'],
            'category' => $category,
            'confidence' => $confidence,
            'duration_ms' => $durationMs,
        ]);

        return response()->json([
            'category' => $category,
            'confidence' => $confidence,
            'reasoning' => $reasoning,
        ]);
    }

    private function logFailure(string $title, float $startedAt, GroqException $e): void
    {
        Log::error('Ticket classification request failed', [
            'title' => $title,
            'duration_ms' => $this->elapsedMs($startedAt),
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
