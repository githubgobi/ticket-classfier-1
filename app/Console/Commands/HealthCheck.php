<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

#[Signature('app:health-check')]
#[Description('Verify local dependencies are configured and reachable: MySQL, Postgres+pgvector, PHP extensions, Ollama, and the Groq API key.')]
class HealthCheck extends Command
{
    public function handle(): int
    {
        $checks = [
            'pdo_mysql PHP extension loaded' => fn () => extension_loaded('pdo_mysql'),
            'pdo_pgsql PHP extension loaded' => fn () => extension_loaded('pdo_pgsql'),
            'MySQL connection (tickets)' => fn () => $this->canConnect('mysql'),
            'Postgres connection (RAG)' => fn () => $this->canConnect('pgsql_rag'),
            'pgvector extension enabled on pgsql_rag' => fn () => $this->pgvectorEnabled(),
            'GROK_API_KEY configured' => fn () => filled(config('services.groq.key')),
            'Ollama reachable' => fn () => $this->ollamaReachable(),
        ];

        $allPassed = true;

        foreach ($checks as $label => $check) {
            try {
                $passed = (bool) $check();
            } catch (Throwable) {
                $passed = false;
            }

            $allPassed = $allPassed && $passed;

            $this->line(($passed ? '<fg=green>[OK]</>' : '<fg=red>[FAIL]</>')." {$label}");
        }

        $this->newLine();

        if (! $allPassed) {
            $this->error('One or more checks failed. See README > Local Development for setup steps.');

            return self::FAILURE;
        }

        $this->info('All checks passed.');

        return self::SUCCESS;
    }

    private function canConnect(string $connection): bool
    {
        DB::connection($connection)->getPdo();

        return true;
    }

    private function pgvectorEnabled(): bool
    {
        $row = DB::connection('pgsql_rag')->selectOne(
            "select extname from pg_extension where extname = 'vector'"
        );

        return $row !== null;
    }

    private function ollamaReachable(): bool
    {
        return Http::timeout(2)->get(config('services.ollama.base_uri').'/api/tags')->successful();
    }
}
