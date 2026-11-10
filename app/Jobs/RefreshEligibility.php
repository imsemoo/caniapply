<?php

namespace App\Jobs;

use App\Services\Discovery\EligibilityRefresher;
use App\Services\Notifications\OperatorAlerts;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/** Too many open jobs for one web request. Safe to run again, so a retry simply finishes the work. */
class RefreshEligibility implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1200;

    public array $backoff = [30, 120];

    public function handle(EligibilityRefresher $refresher): void
    {
        $refresher->run();

        Artisan::call('hunter:reevaluate', ['--all' => true]);
    }

    public function failed(?Throwable $e): void
    {
        app(OperatorAlerts::class)->jobFailed('Country re-check', (string) $e?->getMessage());
    }
}
