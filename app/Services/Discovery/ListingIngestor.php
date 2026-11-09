<?php

namespace App\Services\Discovery;

use App\Enums\CountryEligibility;
use App\Enums\Lifecycle;
use App\Jobs\EvaluateOpportunity;
use App\Models\CandidateProfile;
use App\Models\JobSource;
use App\Models\Opportunity;
use App\Models\OpportunityChange;
use App\Models\OpportunityListing;
use App\Services\Normalization\ListingNormalizer;
use App\Services\Normalization\NormalizedListing;
use App\Sources\RawListing;
use Illuminate\Support\Facades\DB;

class ListingIngestor
{
    public const IRRELEVANT = 'irrelevant';

    public const STALE = 'stale';

    public const CREATED = 'created';

    public const MERGED = 'merged';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    private const REPOST_GAP_DAYS = 21;

    private const GONE_BEFORE_REPOST_DAYS = 7;

    private const SALARY_FIELDS = ['salary_status', 'salary_origin', 'salary_min', 'salary_max', 'salary_currency', 'salary_period', 'monthly_usd_min', 'monthly_usd_max'];

    private const ELIGIBILITY_FIELDS = ['remote_scope', 'country_eligibility', 'eligibility_evidence'];

    public function __construct(
        private ListingNormalizer $normalizer,
        private Deduplicator $deduplicator,
    ) {}

    /** @return string one of the outcome constants */
    public function ingest(JobSource $source, RawListing $raw, CandidateProfile $profile, ?string $forcedTrack = null): string
    {
        $normalized = $this->normalizer->normalize($raw, $profile, $forcedTrack);
        if ($normalized === null) {
            return self::IRRELEVANT;
        }

        $existing = OpportunityListing::query()
            ->where('job_source_id', $source->id)
            ->where('external_id', $raw->externalId)
            ->first();

        if ($existing !== null) {
            return $this->refresh($existing, $normalized);
        }

        if ($raw->postedAt !== null && $raw->postedAt->lt(now()->subDays(config('hunter.discovery.max_age_days')))) {
            return self::STALE;
        }

        [$outcome, $opportunity] = DB::transaction(function () use ($source, $normalized) {
            $match = $this->deduplicator->findMatch($normalized, $source->id);

            return $match !== null
                ? [self::MERGED, $this->merge($match, $source, $normalized)]
                : [self::CREATED, $this->create($source, $normalized)];
        });

        if ($opportunity->needs_evaluation) {
            EvaluateOpportunity::dispatch($opportunity->id);
        }

        return $outcome;
    }

    private function create(JobSource $source, NormalizedListing $listing): Opportunity
    {
        $now = now();
        $opportunity = Opportunity::query()->create($listing->attributes() + [
            'primary_url' => mb_substr($listing->raw->url, 0, 2040),
            'primary_source_id' => $source->id,
            'posted_at' => $listing->raw->postedAt,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'lifecycle' => Lifecycle::New,
            'lifecycle_changed_at' => $now,
            'needs_evaluation' => true,
        ]);

        $this->attachListing($opportunity, $source, $listing);

        return $opportunity;
    }

    private function merge(Opportunity $opportunity, JobSource $source, NormalizedListing $listing): Opportunity
    {
        $this->attachListing($opportunity, $source, $listing);

        $attributes = $listing->attributes();
        $updates = ['last_seen_at' => now()];

        $gapDays = $opportunity->last_seen_at->diffInDays(now());
        if ($this->hasBeenGoneLongEnough($opportunity) || $gapDays >= self::REPOST_GAP_DAYS) {
            $updates += ['lifecycle' => Lifecycle::Reposted, 'lifecycle_changed_at' => now()];
            if ($listing->raw->postedAt !== null) {
                $updates['posted_at'] = $listing->raw->postedAt;
            }
        }

        // A second source can only add what the first one lacked.
        if ($opportunity->salary_status !== 'disclosed' && $listing->salary !== null) {
            $this->recordSalaryChange($opportunity, $listing);
            $updates += array_intersect_key($attributes, array_flip(self::SALARY_FIELDS));
            $updates['needs_evaluation'] = true;
        }

        if (mb_strlen($listing->description) > mb_strlen($opportunity->description) * 1.3) {
            $updates['description'] = $listing->description;
            $updates['needs_evaluation'] = true;
        }

        // A restriction spotted by any source wins over a looser label elsewhere.
        if ($listing->eligibility->country === CountryEligibility::Excluded && $opportunity->country_eligibility !== CountryEligibility::Excluded) {
            $updates += array_intersect_key($attributes, array_flip(self::ELIGIBILITY_FIELDS));
            $updates['needs_evaluation'] = true;
        }

        if ($listing->eligibility->requiresRelocation && ! $opportunity->requires_relocation) {
            $updates['requires_relocation'] = true;
            $updates['needs_evaluation'] = true;
        }

        $opportunity->update($updates);

        return $opportunity;
    }

    private function refresh(OpportunityListing $existing, NormalizedListing $listing): string
    {
        $changed = DB::transaction(function () use ($existing, $listing) {
            $now = now();
            $opportunity = $existing->opportunity;

            $updates = ['last_seen_at' => $now];
            if ($opportunity->lifecycle === Lifecycle::New) {
                $updates += ['lifecycle' => Lifecycle::Seen, 'lifecycle_changed_at' => $now];
            } elseif ($this->hasBeenGoneLongEnough($opportunity)) {
                $updates += ['lifecycle' => Lifecycle::Reposted, 'lifecycle_changed_at' => $now];
            }

            $isPrimary = $opportunity->primary_source_id === $existing->job_source_id;
            $unchanged = $existing->content_hash === $listing->contentHash;

            // A partial listing proves the job is still up and says nothing about its content.
            if ($listing->raw->isPartial || $unchanged || ! $isPrimary) {
                $existing->update(['last_seen_at' => $now] + ($listing->raw->isPartial ? [] : ['content_hash' => $listing->contentHash]));
                $opportunity->update($updates);

                return null;
            }

            $this->recordSalaryChange($opportunity, $listing);
            $this->recordRequirementChange($opportunity, $listing);

            $existing->update(['last_seen_at' => $now, 'content_hash' => $listing->contentHash]);
            $opportunity->update(['needs_evaluation' => true, 'lifecycle' => ($updates['lifecycle'] ?? null) === Lifecycle::Reposted ? Lifecycle::Reposted : Lifecycle::Updated, 'lifecycle_changed_at' => $now]
                + $updates
                + $this->refreshableAttributes($opportunity, $listing));

            return $opportunity;
        });

        if ($changed === null) {
            return self::UNCHANGED;
        }

        EvaluateOpportunity::dispatch($changed->id);

        return self::UPDATED;
    }

    /** An edit to one listing must not erase what another source or a verified quote established. */
    private function refreshableAttributes(Opportunity $opportunity, NormalizedListing $listing): array
    {
        $attributes = $listing->attributes();

        if ($listing->salary === null && $opportunity->salary_status === 'disclosed') {
            $attributes = array_diff_key($attributes, array_flip(self::SALARY_FIELDS));
        }

        if ($opportunity->country_eligibility === CountryEligibility::Excluded) {
            $attributes = array_diff_key($attributes, array_flip(self::ELIGIBILITY_FIELDS));
        }

        $attributes['requires_relocation'] = $listing->eligibility->requiresRelocation || $opportunity->requires_relocation;

        return $attributes;
    }

    private function hasBeenGoneLongEnough(Opportunity $opportunity): bool
    {
        return $opportunity->lifecycle->isGone()
            && $opportunity->lifecycle_changed_at?->lte(now()->subDays(self::GONE_BEFORE_REPOST_DAYS));
    }

    private function recordSalaryChange(Opportunity $opportunity, NormalizedListing $listing): void
    {
        if ($listing->salary === null) {
            return;
        }

        // Compared as stated, so a moved exchange rate is not reported as a pay change.
        $isSame = $opportunity->salary_status === 'disclosed'
            && (float) $opportunity->salary_min === (float) $listing->salary['min']
            && (float) $opportunity->salary_max === (float) $listing->salary['max']
            && $opportunity->salary_currency === $listing->salary['currency']
            && $opportunity->salary_period === $listing->salary['period'];

        if ($isSame) {
            return;
        }

        $next = (clone $opportunity)->forceFill(array_intersect_key($listing->attributes(), array_flip(self::SALARY_FIELDS)));

        OpportunityChange::query()->create([
            'opportunity_id' => $opportunity->id,
            'field' => 'salary',
            'old_value' => $opportunity->compensationLabel(),
            'new_value' => $next->compensationLabel(),
        ]);
    }

    private function recordRequirementChange(Opportunity $opportunity, NormalizedListing $listing): void
    {
        $rewritten = Deduplicator::textOverlap($opportunity->description, $listing->description) < 0.8;
        $yearsMoved = $listing->requiredYears !== null && $listing->requiredYears !== $opportunity->required_years;

        if ($rewritten || $yearsMoved) {
            OpportunityChange::query()->create([
                'opportunity_id' => $opportunity->id,
                'field' => 'requirements',
                'old_value' => mb_substr($opportunity->description, 0, 2000),
                'new_value' => mb_substr($listing->description, 0, 2000),
            ]);
        }
    }

    private function attachListing(Opportunity $opportunity, JobSource $source, NormalizedListing $listing): void
    {
        $now = now();

        $opportunity->listings()->create([
            'job_source_id' => $source->id,
            'external_id' => mb_substr($listing->raw->externalId, 0, 250),
            'url' => mb_substr($listing->raw->url, 0, 2040),
            'url_key' => OpportunityListing::urlKey($listing->raw->url),
            'content_hash' => $listing->contentHash,
            'posted_at' => $listing->raw->postedAt,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
    }
}
