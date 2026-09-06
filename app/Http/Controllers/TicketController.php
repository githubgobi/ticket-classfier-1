<?php

namespace App\Http\Controllers;

use App\Exceptions\GroqConfigurationException;
use App\Exceptions\GroqException;
use App\Exceptions\GroqTimeoutException;
use App\Services\TicketClassifierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TicketController extends Controller
{
    public function __construct(private readonly TicketClassifierService $classifier)
    {
    }

    public function classify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
        ]);

        $startedAt = microtime(true);

        try {
            $result = $this->classifier->classify($validated['title'], $validated['description']);
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

        Log::info('Ticket classified', [
            'title' => $validated['title'],
            'category' => $result['category'],
            'confidence' => $result['confidence'],
            'duration_ms' => $this->elapsedMs($startedAt),
        ]);

        return response()->json($result);
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
