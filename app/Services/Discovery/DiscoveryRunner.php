<?php

namespace App\Services\Discovery;

use App\Models\CandidateProfile;
use App\Models\JobSource;
use App\Models\SourceRun;
use App\Services\Notifications\OperatorAlerts;
use App\Sources\SourceRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

class DiscoveryRunner
{
    public function __construct(
        private SourceRegistry $registry,
        private ListingIngestor $ingestor,
        private OperatorAlerts $operatorAlerts,
    ) {}

    public function run(JobSource $source): SourceRun
    {
        $run = $source->runs()->create(['started_at' => now()]);
        $profile = CandidateProfile::current();
        $counts = ['fetched' => 0, ListingIngestor::IRRELEVANT => 0, ListingIngestor::STALE => 0, ListingIngestor::CREATED => 0, ListingIngestor::MERGED => 0, ListingIngestor::UPDATED => 0];
        $listingErrors = 0;

        try {
            $connector = $this->registry->connector($source->key);

            foreach ($connector->fetch($profile) as $raw) {
                $counts['fetched']++;

                try {
                    $outcome = $this->ingestor->ingest($source, $raw, $profile);
                    if (isset($counts[$outcome])) {
                        $counts[$outcome]++;
                    }
                } catch (Throwable $e) {
                    // One malformed listing must not cost the rest of the feed.
                    $listingErrors++;
                    Log::warning("Listing failed in {$source->key}", ['external_id' => $raw->externalId, 'error' => $e->getMessage()]);
                }
            }
        } catch (Throwable $e) {
            return $this->finishFailed($source, $run, $counts, $e);
        }

        return $this->finishOk($source, $run, $counts, $listingErrors, $connector->partialFailures());
    }

    private function finishOk(JobSource $source, SourceRun $run, array $counts, int $listingErrors, array $partialFailures): SourceRun
    {
        $wentSilent = $counts['fetched'] === 0 && $source->last_fetched > 0;

        $run->update($counts + [
            'status' => $wentSilent ? 'empty' : 'ok',
            'error' => $listingErrors > 0 ? "{$listingErrors} listings failed to process" : null,
            'finished_at' => now(),
        ]);

        if ($wentSilent) {
            // Usually a changed page layout or API shape, so count it towards alerting.
            $source->update(['health' => 'degraded', 'last_run_at' => now(), 'last_error' => 'The source returned no listings', 'consecutive_failures' => $source->consecutive_failures + 1]);
            $this->operatorAlerts->sourceFailing($source->fresh());

            return $run;
        }

        if ($partialFailures !== []) {
            // last_success_at stays put: absences from this run prove nothing about closed jobs.
            $run->update(['status' => 'partial', 'error' => mb_substr(implode('; ', $partialFailures), 0, 500)]);
            $source->update(['health' => 'degraded', 'last_run_at' => now(), 'last_error' => mb_substr(implode('; ', $partialFailures), 0, 500), 'consecutive_failures' => 0, 'last_fetched' => $counts['fetched']]);

            return $run;
        }

        $source->update([
            'health' => 'ok',
            'last_run_at' => now(),
            'last_success_at' => now(),
            'last_error' => null,
            'consecutive_failures' => 0,
            'failure_alerted_at' => null,
            'last_fetched' => $counts['fetched'],
        ]);

        return $run;
    }

    private function finishFailed(JobSource $source, SourceRun $run, array $counts, Throwable $e): SourceRun
    {
        $message = mb_substr($e->getMessage(), 0, 500);
        Log::error("Source {$source->key} failed", ['error' => $message]);

        $run->update($counts + ['status' => 'failed', 'error' => $message, 'finished_at' => now()]);

        $source->update([
            'health' => 'failing',
            'last_run_at' => now(),
            'last_error' => $message,
            'consecutive_failures' => $source->consecutive_failures + 1,
        ]);

        $this->operatorAlerts->sourceFailing($source->fresh());

        return $run;
    }
}
