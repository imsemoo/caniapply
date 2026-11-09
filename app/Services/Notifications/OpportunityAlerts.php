<?php

namespace App\Services\Notifications;

use App\Enums\Bucket;
use App\Models\Alert;
use App\Models\CandidateProfile;
use App\Models\Opportunity;
use App\Models\OpportunityChange;
use Illuminate\Support\Collection;

class OpportunityAlerts
{
    public function __construct(private TelegramBot $bot, private MessageComposer $composer) {}

    /** Called after every evaluation. Safe to call repeatedly for the same job. */
    public function afterEvaluation(Opportunity $job): void
    {
        if (! $this->bot->isConfigured()) {
            return;
        }

        $this->sendPendingChanges($job);
        $this->sendImmediate($job);
    }

    public function sendDailyDigest(): bool
    {
        if (! $this->bot->isConfigured()) {
            return false;
        }

        // Change alerts that failed earlier get another chance here.
        Opportunity::query()->whereHas('changes', fn ($q) => $q->whereNull('alerted_at'))->each(fn (Opportunity $job) => $this->sendPendingChanges($job));

        $threshold = CandidateProfile::current()->thresholds['digest'];
        $eligible = $this->digestCandidates($threshold);

        $freelance = $eligible->filter(fn (Opportunity $j) => $j->isFreelance())->take(8)->values();
        $undisclosed = $eligible->filter(fn (Opportunity $j) => $j->bucket === Bucket::Undisclosed)->take(8)->values();
        $top = $eligible->filter(fn (Opportunity $j) => $j->bucket === Bucket::Qualified && ! $j->isFreelance())->take(12)->values();

        $flagged = Opportunity::query()->active()->where('bucket', Bucket::Flagged->value)->where('first_seen_at', '>=', now()->subDay())->count();

        $message = $this->composer->digest($top, $undisclosed, $freelance, $flagged, [
            'new_today' => Opportunity::query()->where('first_seen_at', '>=', now()->subDay())->count(),
            'qualified_today' => Opportunity::query()->where('first_seen_at', '>=', now()->subDay())->whereIn('bucket', [Bucket::Qualified->value, Bucket::Undisclosed->value])->count(),
        ]);

        if (! $this->bot->send($message)) {
            return false;
        }

        foreach ($top->concat($undisclosed)->concat($freelance) as $job) {
            $this->claim('digest', $job->id, (string) $job->id, $job->id);
        }

        return true;
    }

    private function digestCandidates(int $threshold): Collection
    {
        return Opportunity::query()->active()
            ->whereIn('bucket', [Bucket::Qualified->value, Bucket::Undisclosed->value])
            ->where('score', '>=', $threshold)
            ->where('first_seen_at', '>=', now()->subDays(7))
            ->whereNotIn('id', Alert::query()->whereIn('kind', ['immediate', 'digest'])->whereNotNull('opportunity_id')->select('opportunity_id'))
            ->get()
            ->sortByDesc(fn (Opportunity $j) => [$this->freshnessRank($j), $j->score])
            ->values();
    }

    private function sendImmediate(Opportunity $job): void
    {
        $profile = CandidateProfile::current();

        $qualifies = in_array($job->bucket, [Bucket::Qualified, Bucket::Undisclosed], true)
            && $job->score >= $profile->thresholds['immediate']
            && ! $job->lifecycle->isGone()
            && $job->freshnessAt()->gte(now()->subDays(config('hunter.telegram.alert_max_age_days')));

        if (! $qualifies) {
            return;
        }

        // Over the cap, the job simply waits for the digest.
        // Already shown in a digest: from here on only a change alert is news.
        if (Alert::query()->where('kind', 'digest')->where('opportunity_id', $job->id)->exists()) {
            return;
        }

        $sentRecently = Alert::query()->where('kind', 'immediate')->where('sent_at', '>=', now()->subHours(3))->count();
        if ($sentRecently >= config('hunter.telegram.max_immediate_per_run')) {
            return;
        }

        if (! $this->claim('immediate', $job->id, (string) $job->id, $job->id)) {
            return;
        }

        $job->loadMissing('listings.source');

        if (! $this->bot->send($this->composer->immediate($job), $this->composer->immediateKeyboard($job))) {
            $this->release('immediate', (string) $job->id);
        }
    }

    private function sendPendingChanges(Opportunity $job): void
    {
        $pending = OpportunityChange::query()->where('opportunity_id', $job->id)->whereNull('alerted_at')->get();

        if ($pending->isEmpty()) {
            return;
        }

        $wasShown = Alert::query()->where('opportunity_id', $job->id)->exists();
        $threshold = CandidateProfile::current()->thresholds['digest'];
        $tracked = $job->application()->exists();

        if (! $wasShown && ! $tracked && $job->score < $threshold) {
            OpportunityChange::query()->whereIn('id', $pending->pluck('id'))->update(['alerted_at' => now()]);

            return;
        }

        $fingerprint = hash('sha256', $pending->pluck('id')->implode(','));

        if ($this->claim('change', $job->id, $fingerprint, $job->id)) {
            if ($this->bot->send($this->composer->change($job, $pending), $this->composer->linkRow($job))) {
                OpportunityChange::query()->whereIn('id', $pending->pluck('id'))->update(['alerted_at' => now()]);
            } else {
                $this->release('change', $fingerprint);
            }
        }
    }

    /** The unique index is what guarantees a job is never announced twice. */
    private function claim(string $kind, ?int $opportunityId, string $fingerprint, ?int $forJob = null): bool
    {
        return Alert::query()->insertOrIgnore([
            'kind' => $kind,
            'opportunity_id' => $forJob ?? $opportunityId,
            'fingerprint' => $fingerprint,
            'sent_at' => now(),
        ]) === 1;
    }

    private function release(string $kind, string $fingerprint): void
    {
        Alert::query()->where('kind', $kind)->where('fingerprint', $fingerprint)->delete();
    }

    private function freshnessRank(Opportunity $job): int
    {
        $hours = $job->freshnessAt()->diffInHours(now());

        return match (true) {
            $hours <= 24 => 3,
            $hours <= 72 => 2,
            $hours <= 168 => 1,
            default => 0,
        };
    }
}
