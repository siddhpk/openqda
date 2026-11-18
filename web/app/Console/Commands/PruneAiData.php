<?php

namespace App\Console\Commands;

use App\Models\AiEmbedding;
use App\Models\AiUsageLog;
use Illuminate\Console\Command;

/**
 * Removes cached embeddings older than the configured TTL and usage logs
 * older than a year. Scheduled daily.
 */
class PruneAiData extends Command
{
    protected $signature = 'ai:prune {--days= : Override AI_CACHE_TTL_DAYS} {--all : Delete every cached embedding}';

    protected $description = 'Prune cached AI embeddings (and old AI usage logs)';

    public function handle(): int
    {
        if ($this->option('all')) {
            $deleted = AiEmbedding::query()->delete();
            $this->info("Deleted {$deleted} cached embeddings.");

            return self::SUCCESS;
        }

        $days = (int) ($this->option('days') ?: config('ai.cache.ttl_days', 90));
        $deleted = AiEmbedding::where('created_at', '<', now()->subDays(max(1, $days)))->delete();
        $logs = AiUsageLog::where('created_at', '<', now()->subYear())->delete();
        $this->info("Deleted {$deleted} cached embeddings older than {$days} days and {$logs} old usage log entries.");

        return self::SUCCESS;
    }
}
