<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunEvalTest extends TestCase
{
    private string $goldenPath;

    private string $historyPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.groq.key' => 'test-key']);

        $this->goldenPath = 'tests/golden/_eval_test_fixture.json';
        $this->historyPath = 'tests/golden/_eval_test_history.jsonl';

        file_put_contents(base_path($this->goldenPath), json_encode([
            'version' => 'test',
            'categories' => ['bug', 'feature-request', 'documentation', 'other'],
            'examples' => [
                ['id' => 'e1', 'title' => 'Crash on open', 'description' => 'It crashes.', 'expected_category' => 'bug', 'case_type' => 'clear'],
                ['id' => 'e2', 'title' => 'Add dark mode', 'description' => 'Please add it.', 'expected_category' => 'feature-request', 'case_type' => 'clear'],
                ['id' => 'e3', 'title' => 'Broken link', 'description' => 'Docs link 404s.', 'expected_category' => 'documentation', 'case_type' => 'clear'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink(base_path($this->goldenPath));
        @unlink(base_path($this->historyPath));

        parent::tearDown();
    }

    private function fakeGroqSequence(array $categories): void
    {
        $responses = array_map(
            fn ($category) => Http::response(['choices' => [['message' => ['content' => json_encode([
                'category' => $category,
                'confidence' => 0.9,
                'reasoning' => 'test',
            ])]]]]),
            $categories
        );

        Http::fake(['api.groq.com/*' => Http::sequence($responses)]);
    }

    public function test_it_reports_accuracy_and_a_confusion_matrix(): void
    {
        // e1 correct (bug), e2 wrong (says documentation instead of feature-request), e3 correct.
        $this->fakeGroqSequence(['bug', 'documentation', 'documentation']);

        $this->artisan('eval:run', [
            '--golden' => $this->goldenPath,
            '--delay' => 0,
            '--history' => $this->historyPath,
        ])->assertExitCode(0);

        $history = file(base_path($this->historyPath));
        $this->assertCount(1, $history);

        $summary = json_decode($history[0], true);
        $this->assertSame(2, $summary['correct']);
        $this->assertSame(3, $summary['total']);
        $this->assertEqualsWithDelta(66.7, $summary['accuracy'], 0.1);
    }

    public function test_it_retries_on_groq_failure_and_records_an_error_after_exhausting_retries(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => 'bad request'], 400)]);

        $this->artisan('eval:run', [
            '--golden' => $this->goldenPath,
            '--delay' => 0,
            '--retries' => 2,
            '--history' => $this->historyPath,
        ])->assertExitCode(0);

        $summary = json_decode(file_get_contents(base_path($this->historyPath)), true);

        // All 3 examples error out (Groq always fails) — excluded from the
        // accuracy denominator entirely, not counted as wrong.
        $this->assertSame(0, $summary['total']);
        $this->assertSame(3, $summary['errored']);

        Http::assertSentCount(3 * 2); // 3 examples x 2 retries each
    }

    public function test_it_fails_cleanly_when_the_golden_set_is_missing(): void
    {
        $this->artisan('eval:run', ['--golden' => 'tests/golden/does-not-exist.json'])
            ->assertExitCode(1);
    }
}
