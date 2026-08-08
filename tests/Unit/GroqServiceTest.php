<?php

namespace Tests\Unit;

use App\Exceptions\GroqConfigurationException;
use App\Exceptions\GroqException;
use App\Exceptions\GroqTimeoutException;
use App\Services\GroqService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroqServiceTest extends TestCase
{
    public function test_it_returns_the_completion_content(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '{"category":"bug"}']],
                ],
            ], 200),
        ]);

        $service = new GroqService(apiKey: 'test-key', baseUri: 'https://api.groq.com/openai/v1', model: 'llama-3.3-70b-versatile');

        $result = $service->chat([
            ['role' => 'user', 'content' => 'Classify this ticket: I was charged twice.'],
        ]);

        $this->assertSame('{"category":"bug"}', $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request['model'] === 'llama-3.3-70b-versatile';
        });
    }

    public function test_it_forwards_the_response_format_option(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '{}']],
                ],
            ], 200),
        ]);

        $service = new GroqService(apiKey: 'test-key', baseUri: 'https://api.groq.com/openai/v1', model: 'llama-3.3-70b-versatile');

        $service->chat(
            [['role' => 'user', 'content' => 'Classify this ticket.']],
            ['response_format' => ['type' => 'json_object']],
        );

        Http::assertSent(fn ($request) => ($request['response_format']['type'] ?? null) === 'json_object');
    }

    public function test_it_throws_a_configuration_exception_when_the_api_key_is_missing(): void
    {
        $service = new GroqService(apiKey: '', baseUri: 'https://api.groq.com/openai/v1', model: 'llama-3.3-70b-versatile');

        $this->expectException(GroqConfigurationException::class);

        $service->chat([['role' => 'user', 'content' => 'Classify this ticket.']]);
    }

    public function test_it_throws_a_groq_exception_when_the_api_request_fails(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['error' => 'bad request'], 400),
        ]);

        $service = new GroqService(apiKey: 'test-key', baseUri: 'https://api.groq.com/openai/v1', model: 'llama-3.3-70b-versatile');

        $this->expectException(GroqException::class);

        $service->chat([['role' => 'user', 'content' => 'Classify this ticket.']]);
    }

    public function test_it_throws_a_timeout_exception_when_the_connection_times_out(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
        });

        $service = new GroqService(apiKey: 'test-key', baseUri: 'https://api.groq.com/openai/v1', model: 'llama-3.3-70b-versatile', timeout: 1);

        $this->expectException(GroqTimeoutException::class);

        $service->chat([['role' => 'user', 'content' => 'Classify this ticket.']]);
    }
}
