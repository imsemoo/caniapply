<?php

namespace App\Services\Discovery;

use App\Enums\Lifecycle;
use App\Models\Opportunity;
use App\Sources\SourceHttp;

class ListingRechecker
{
    public function __construct(private SourceHttp $http) {}

    /** @return array{expired: int, closed: int, checked: int} */
    public function run(): array
    {
        $expired = $this->expireOld();
        $closedAbsent = $this->closeMissingFromCompanyBoards();
        [$checked, $closedByStatus] = $this->recheckImportant();

        return ['expired' => $expired, 'closed' => $closedAbsent + $closedByStatus, 'checked' => $checked];
    }

    private function expireOld(): int
    {
        $now = now();
        $ageCutoff = $now->copy()->subDays(config('hunter.discovery.expire_after_days'));

        return Opportunity::query()->active()
            ->where(function ($query) use ($now, $ageCutoff) {
                $query->where('expires_at', '<', $now)
                    ->orWhere(fn ($q) => $q->where('last_seen_at', '<', $now->copy()->subDays(14))->where('first_seen_at', '<', $ageCutoff));
            })
            ->update(['lifecycle' => Lifecycle::Expired->value, 'lifecycle_changed_at' => $now]);
    }

    /** Company boards publish their full list, so absence for days means the role is gone. */
    private function closeMissingFromCompanyBoards(): int
    {
        $closed = 0;

        Opportunity::query()->active()
            ->where('last_seen_at', '<', now()->subDays(3))
            ->whereHas('primarySource', fn ($q) => $q->where('type', 'ats')->where('last_success_at', '>=', now()->subDay()))
            ->each(function (Opportunity $opportunity) use (&$closed) {
                $opportunity->update(['lifecycle' => Lifecycle::Closed, 'lifecycle_changed_at' => now()]);
                $closed++;
            });

        return $closed;
    }

    /** @return array{0: int, 1: int} */
    private function recheckImportant(): array
    {
        $minScore = config('hunter.discovery.recheck_min_score');
        $candidates = Opportunity::query()->active()
            ->where('score', '>=', $minScore)
            ->whereNotNull('primary_url')
            ->whereDoesntHave('primarySource', fn ($q) => $q->where('enabled', false))
            ->orderBy('last_rechecked_at')
            ->limit(config('hunter.discovery.recheck_limit'))
            ->get();

        $closed = 0;

        foreach ($candidates as $opportunity) {
            $url = $opportunity->safeUrl();
            $status = $url !== null ? $this->http->statusOf($url) : null;

            // Only an explicit "gone" counts. 403 and friends are bot walls, not closures.
            if (in_array($status, [404, 410], true)) {
                $opportunity->update(['lifecycle' => Lifecycle::Closed, 'lifecycle_changed_at' => now()]);
                $closed++;
            }

            $opportunity->update(['last_rechecked_at' => now()]);
        }

        return [$candidates->count(), $closed];
    }
}
