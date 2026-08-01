<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TicketClassifierTest extends TestCase
{
    public function test_it_classifies_a_ticket(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'billing']],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/classify', [
            'message' => 'I was charged twice for my subscription this month.',
        ]);

        $response->assertOk()->assertJson([
            'message' => 'I was charged twice for my subscription this month.',
            'category' => 'billing',
        ]);
    }

    public function test_it_falls_back_to_general_for_an_unrecognized_category(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'not a real category']],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/classify', [
            'message' => 'Something strange happened.',
        ]);

        $response->assertOk()->assertJson([
            'category' => 'general',
        ]);
    }

    public function test_it_requires_a_message(): void
    {
        $response = $this->postJson('/api/classify', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['message']);
    }
}
