<?php

namespace App\Console\Commands;

use App\Exceptions\GroqException;
use App\Services\TicketClassifierService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('eval:run {--golden=tests/golden/tickets.json} {--delay=2 : Seconds to wait between Groq calls} {--retries=3} {--history=docs/eval-history.jsonl}')]
#[Description('Run the golden set against the real classifier: accuracy, a confusion matrix, and drift history.')]
class RunEval extends Command
{
    public function __construct(private readonly TicketClassifierService $classifier)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $goldenPath = base_path($this->option('golden'));

        if (! is_file($goldenPath)) {
            $this->error("Golden set not found: {$goldenPath}");

            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($goldenPath), true);
        $categories = $data['categories'];
        $examples = $data['examples'];

        $delay = (int) $this->option('delay');
        $maxRetries = (int) $this->option('retries');

        $this->info(sprintf('Running %d golden examples against the live classifier (model: %s)...', count($examples), config('services.groq.model')));

        $bar = $this->output->createProgressBar(count($examples));
        $bar->start();

        $results = [];

        foreach ($examples as $example) {
            $results[] = $this->classifyWithRetry($example, $maxRetries);
            $bar->advance();

            if ($delay > 0) {
                sleep($delay);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $results = collect($results);
        $this->reportSummary($results, $categories);
        $this->reportConfusionMatrix($results, $categories);
        $this->reportMismatches($results);
        $this->reportErrors($results);
        $this->saveHistory($results, $data['version']);

        return self::SUCCESS;
    }

    /**
     * @return array{id: string, title: string, case_type: string, expected: string, actual: ?string, confidence: ?float, reasoning: ?string, match: bool, error: ?string}
     */
    private function classifyWithRetry(array $example, int $maxRetries): array
    {
        $error = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $result = $this->classifier->classify($example['title'], $example['description']);

                return [
                    'id' => $example['id'],
                    'title' => $example['title'],
                    'case_type' => $example['case_type'],
                    'expected' => $example['expected_category'],
                    'actual' => $result['category'],
                    'confidence' => $result['confidence'],
                    'reasoning' => $result['reasoning'],
                    'match' => $result['category'] === $example['expected_category'],
                    'error' => null,
                ];
            } catch (GroqException $e) {
                $error = $e->getMessage();

                if ($attempt < $maxRetries) {
                    sleep(3 * $attempt); // backoff: 3s, 6s, ...
                }
            }
        }

        return [
            'id' => $example['id'],
            'title' => $example['title'],
            'case_type' => $example['case_type'],
            'expected' => $example['expected_category'],
            'actual' => null,
            'confidence' => null,
            'reasoning' => null,
            'match' => false,
            'error' => $error,
        ];
    }

    private function reportSummary(Collection $results, array $categories): void
    {
        $graded = $results->whereNotNull('actual');
        $errored = $results->whereNull('actual');
        $correct = $graded->where('match', true)->count();
        $total = $graded->count();
        $accuracy = $total > 0 ? round($correct / $total * 100, 1) : 0.0;

        $this->components->twoColumnDetail('<fg=cyan>Accuracy</>', "{$correct}/{$total} ({$accuracy}%)");

        if ($errored->isNotEmpty()) {
            $this->components->twoColumnDetail('<fg=red>Errored (excluded from accuracy)</>', (string) $errored->count());
        }

        $this->newLine();
        $this->line('By case type:');

        foreach ($graded->groupBy('case_type') as $type => $group) {
            $c = $group->where('match', true)->count();
            $t = $group->count();
            $this->line(sprintf('  %-12s %d/%d', $type, $c, $t));
        }
    }

    private function reportConfusionMatrix(Collection $results, array $categories): void
    {
        $matrix = [];

        foreach ($categories as $expected) {
            foreach ($categories as $actual) {
                $matrix[$expected][$actual] = 0;
            }
        }

        foreach ($results->whereNotNull('actual') as $r) {
            $matrix[$r['expected']][$r['actual']]++;
        }

        $this->newLine();
        $this->line('Confusion matrix (rows = expected, cols = actual):');

        $rows = [];
        foreach ($categories as $expected) {
            $row = [$expected];
            foreach ($categories as $actual) {
                $row[] = $matrix[$expected][$actual];
            }
            $rows[] = $row;
        }

        $this->table(array_merge(['expected \\ actual'], $categories), $rows);
    }

    private function reportMismatches(Collection $results): void
    {
        $mismatches = $results->whereNotNull('actual')->where('match', false);

        if ($mismatches->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('<fg=yellow>Mismatches:</>');

        foreach ($mismatches as $m) {
            $this->line(sprintf(
                '  [%s] (%s) expected=%s actual=%s — "%s"',
                $m['id'],
                $m['case_type'],
                $m['expected'],
                $m['actual'],
                $m['title']
            ));
        }
    }

    private function reportErrors(Collection $results): void
    {
        $errored = $results->whereNull('actual');

        if ($errored->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('<fg=red>Errored (excluded from accuracy):</>');

        foreach ($errored as $e) {
            $this->line("  [{$e['id']}] {$e['error']}");
        }
    }

    private function saveHistory(Collection $results, string $goldenSetVersion): void
    {
        $graded = $results->whereNotNull('actual');
        $correct = $graded->where('match', true)->count();
        $total = $graded->count();

        $detailPath = storage_path('app/eval-results/'.now()->format('Y-m-d_His').'.json');

        if (! is_dir(dirname($detailPath))) {
            mkdir(dirname($detailPath), 0755, true);
        }

        file_put_contents($detailPath, json_encode([
            'timestamp' => now()->toIso8601String(),
            'golden_set_version' => $goldenSetVersion,
            'model' => config('services.groq.model'),
            'correct' => $correct,
            'total' => $total,
            'errored' => $results->whereNull('actual')->count(),
            'results' => $results->values(),
        ], JSON_PRETTY_PRINT));

        $summaryLine = json_encode([
            'timestamp' => now()->toIso8601String(),
            'golden_set_version' => $goldenSetVersion,
            'model' => config('services.groq.model'),
            'accuracy' => $total > 0 ? round($correct / $total * 100, 1) : 0.0,
            'correct' => $correct,
            'total' => $total,
            'errored' => $results->whereNull('actual')->count(),
        ]);

        $historyPath = base_path($this->option('history'));
        file_put_contents($historyPath, $summaryLine.PHP_EOL, FILE_APPEND);

        $this->newLine();
        $this->info("Full results: {$detailPath}");
        $this->info('Summary appended to docs/eval-history.jsonl (committed, for drift tracking over time).');
    }
}
