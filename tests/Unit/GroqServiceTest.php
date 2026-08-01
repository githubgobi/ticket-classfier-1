<?php

namespace Tests\Unit;

use App\Services\GroqService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GroqServiceTest extends TestCase
{
    public function test_it_returns_the_completion_content(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Billing issue']],
                ],
            ], 200),
        ]);

        $service = new GroqService(apiKey: 'test-key', baseUri: 'https://api.groq.com/openai/v1', model: 'llama-3.3-70b-versatile');

        $result = $service->chat('Classify this ticket: I was charged twice.');

        $this->assertSame('Billing issue', $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request['model'] === 'llama-3.3-70b-versatile';
        });
    }

    public function test_it_throws_when_the_api_key_is_missing(): void
    {
        $service = new GroqService(apiKey: '', baseUri: 'https://api.groq.com/openai/v1', model: 'llama-3.3-70b-versatile');

        $this->expectException(RuntimeException::class);

        $service->chat('Classify this ticket.');
    }

    public function test_it_throws_when_the_api_request_fails(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['error' => 'bad request'], 400),
        ]);

        $service = new GroqService(apiKey: 'test-key', baseUri: 'https://api.groq.com/openai/v1', model: 'llama-3.3-70b-versatile');

        $this->expectException(RuntimeException::class);

        $service->chat('Classify this ticket.');
    }
}
