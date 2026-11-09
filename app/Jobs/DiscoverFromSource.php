<?php

namespace App\Jobs;

use App\Models\JobSource;
use App\Services\Discovery\DiscoveryRunner;
use App\Services\Notifications\OperatorAlerts;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DiscoverFromSource implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public int $uniqueFor = 3000;

    public function __construct(public int $sourceId) {}

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public function handle(DiscoveryRunner $runner): void
    {
        $runner->run(JobSource::query()->findOrFail($this->sourceId));
    }

    public function failed(?Throwable $e): void
    {
        app(OperatorAlerts::class)->jobFailed('Discovery '.$this->sourceId, (string) $e?->getMessage());
    }
}
