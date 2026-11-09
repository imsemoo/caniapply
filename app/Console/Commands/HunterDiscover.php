<?php

namespace App\Console\Commands;

use App\Jobs\DiscoverFromSource;
use App\Models\JobSource;
use App\Sources\SourceRegistry;
use Illuminate\Console\Command;

class HunterDiscover extends Command
{
    protected $signature = 'hunter:discover {--source= : Only this source key} {--now : Run in this process instead of the queue} {--force : Ignore the per-source minimum interval}';

    protected $description = 'Queue discovery for every enabled source that is due';

    public function handle(SourceRegistry $registry): int
    {
        $registry->sync();

        $sources = JobSource::query()
            ->where('enabled', true)
            ->where('type', '!=', 'manual')
            ->when($this->option('source'), fn ($q, $key) => $q->where('key', $key))
            ->get();

        foreach ($sources as $source) {
            $interval = config("hunter.sources.{$source->key}.min_interval_hours", 3);

            if (! $this->option('force') && ! $source->isDue($interval)) {
                $this->line("skip {$source->key}: not due");

                continue;
            }

            if ($this->option('now')) {
                DiscoverFromSource::dispatchSync($source->id);
                $run = $source->runs()->latest('id')->first();
                $this->line(sprintf('%-16s %-7s fetched %-4d irrelevant %-4d stale %-4d new %-3d merged %-3d updated %-3d %s', $source->key, $run->status, $run->fetched, $run->irrelevant, $run->stale, $run->created, $run->merged, $run->updated, $run->error));
            } else {
                DiscoverFromSource::dispatch($source->id);
                $this->line("queued {$source->key}");
            }
        }

        if ($this->option('now')) {
            $this->call('hunter:reevaluate', ['--now' => true]);
        }

        return self::SUCCESS;
    }
}
