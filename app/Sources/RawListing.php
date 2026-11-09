<?php

namespace App\Sources;

use App\Enums\CountryEligibility;
use App\Enums\RemoteScope;
use Illuminate\Support\Carbon;

/** One listing exactly as a source reports it, before any interpretation. */
final class RawListing
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $url,
        public readonly string $title,
        public readonly ?string $company = null,
        public readonly string $description = '',
        public readonly ?string $location = null,
        public readonly ?Carbon $postedAt = null,
        public readonly ?Carbon $expiresAt = null,
        public readonly ?string $employmentType = null,
        public readonly array $tags = [],
        public readonly ?float $salaryMin = null,
        public readonly ?float $salaryMax = null,
        public readonly ?string $salaryCurrency = null,
        public readonly ?string $salaryPeriod = null,
        public readonly ?bool $remote = null,
        // Set only when the source itself states who may apply.
        public readonly ?RemoteScope $sourceScope = null,
        public readonly ?CountryEligibility $sourceEligibility = null,
        public readonly ?string $sourceEvidence = null,
        public readonly bool $isFreelance = false,
        public readonly ?string $budgetType = null,
        public readonly ?float $budgetMin = null,
        public readonly ?float $budgetMax = null,
        public readonly ?string $budgetCurrency = null,
        public readonly array $clientSignals = [],
        // Remote OK and others pad postings with their own estimates.
        public readonly bool $salaryIsEstimate = false,
        // Only a summary was read this time: confirms the job is live, says nothing new.
        public readonly bool $isPartial = false,
    ) {}
}
