<?php

namespace App\Http\Controllers;

use App\Services\GroqService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    private const CATEGORIES = ['billing', 'technical', 'account', 'general'];

    public function __construct(private readonly GroqService $groq)
    {
    }

    public function classify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $prompt = sprintf(
            "Classify the following support ticket into exactly one of these categories: %s.\n".
            "Respond with only the category name, nothing else.\n\nTicket: %s",
            implode(', ', self::CATEGORIES),
            $validated['message'],
        );

        $category = strtolower(trim($this->groq->chat($prompt)));

        if (! in_array($category, self::CATEGORIES, true)) {
            $category = 'general';
        }

        return response()->json([
            'message' => $validated['message'],
            'category' => $category,
        ]);
    }
}
