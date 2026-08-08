<?php

namespace Tests\Feature;

use App\Exceptions\GroqTimeoutException;
use App\Services\GroqService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TicketClassifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The throttle middleware's rate-limit counters live in the default
        // cache store, which persists across tests within one PHPUnit run.
        Cache::flush();
    }

    private function fakeGroqResponse(string $category, float $confidence, string $reasoning): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'category' => $category,
                        'confidence' => $confidence,
                        'reasoning' => $reasoning,
                    ])]],
                ],
            ], 200),
        ]);
    }

    public function test_it_classifies_a_bug_report(): void
    {
        $this->fakeGroqResponse('bug', 0.97, 'Describes a crash on login.');

        $response = $this->postJson('/api/classify', [
            'title' => 'App crashes on login',
            'description' => 'Tapping "Sign in" closes the app immediately on iOS 17.',
        ]);

        $response->assertOk()->assertJson([
            'category' => 'bug',
            'confidence' => 0.97,
            'reasoning' => 'Describes a crash on login.',
        ]);
    }

    public function test_it_classifies_a_feature_request(): void
    {
        $this->fakeGroqResponse('feature-request', 0.95, 'Requests new functionality.');

        $response = $this->postJson('/api/classify', [
            'title' => 'Add dark mode',
            'description' => 'Would love a dark theme option in settings.',
        ]);

        $response->assertOk()->assertJson([
            'category' => 'feature-request',
            'confidence' => 0.95,
        ]);
    }

    public function test_it_classifies_a_documentation_issue(): void
    {
        $this->fakeGroqResponse('documentation', 0.9, 'The README install steps are wrong.');

        $response = $this->postJson('/api/classify', [
            'title' => 'README setup steps are wrong',
            'description' => 'The install command in the README fails on a fresh clone.',
        ]);

        $response->assertOk()->assertJson([
            'category' => 'documentation',
            'confidence' => 0.9,
        ]);
    }

    public function test_it_classifies_a_general_inquiry_as_other(): void
    {
        $this->fakeGroqResponse('other', 0.8, 'A general inquiry, not a bug, feature, or docs issue.');

        $response = $this->postJson('/api/classify', [
            'title' => 'General question',
            'description' => 'Do you offer student discounts?',
        ]);

        $response->assertOk()->assertJson([
            'category' => 'other',
            'confidence' => 0.8,
        ]);
    }

    public function test_it_falls_back_to_other_for_an_unrecognized_category(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode(['category' => 'not-a-real-category'])]],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/classify', [
            'title' => 'Something strange happened',
            'description' => 'Not sure what this is about.',
        ]);

        $response->assertOk()->assertJson(['category' => 'other']);
    }

    public function test_it_requires_a_title_and_description(): void
    {
        $response = $this->postJson('/api/classify', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['title', 'description']);
    }

    public function test_it_returns_a_504_when_the_groq_request_times_out(): void
    {
        $this->mock(GroqService::class, function ($mock) {
            $mock->shouldReceive('chat')->andThrow(new GroqTimeoutException('Groq API request timed out.'));
        });

        $response = $this->postJson('/api/classify', [
            'title' => 'App crashes on login',
            'description' => 'Tapping "Sign in" closes the app immediately.',
        ]);

        $response->assertStatus(504)->assertJson(['error' => 'The classification service timed out. Please try again.']);
    }

    public function test_it_is_rate_limited_after_five_requests_per_minute(): void
    {
        $this->fakeGroqResponse('bug', 0.9, 'Looks like a bug.');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/classify', [
                'title' => 'App crashes on login',
                'description' => 'Tapping "Sign in" closes the app immediately.',
            ])->assertOk();
        }

        $this->postJson('/api/classify', [
            'title' => 'App crashes on login',
            'description' => 'Tapping "Sign in" closes the app immediately.',
        ])->assertStatus(429);
    }
}
