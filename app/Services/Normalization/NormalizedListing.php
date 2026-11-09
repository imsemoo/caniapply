<?php

namespace App\Services\Normalization;

use App\Enums\EmploymentType;
use App\Sources\RawListing;

final class NormalizedListing
{
    public function __construct(
        public readonly RawListing $raw,
        public readonly string $track,
        public readonly string $description,
        public readonly string $titleNorm,
        public readonly ?string $companyNorm,
        public readonly EmploymentType $employmentType,
        public readonly EligibilityFinding $eligibility,
        public readonly ?array $salary,
        public readonly ?string $salaryOrigin,
        public readonly ?float $monthlyUsdMin,
        public readonly ?float $monthlyUsdMax,
        public readonly ?float $budgetUsdMin,
        public readonly ?float $budgetUsdMax,
        public readonly ?string $seniority,
        public readonly ?int $requiredYears,
        public readonly string $contentHash,
    ) {}

    /** Column values shared by create and update. */
    public function attributes(): array
    {
        return [
            'track' => $this->track,
            'employment_type' => $this->employmentType,
            'title' => mb_substr(TextCleaner::plain($this->raw->title), 0, 250),
            'title_norm' => mb_substr($this->titleNorm, 0, 250),
            'company' => $this->raw->company ? mb_substr(TextCleaner::plain($this->raw->company), 0, 250) : null,
            'company_norm' => $this->companyNorm ? mb_substr($this->companyNorm, 0, 250) : null,
            'description' => $this->description,
            'location_text' => $this->raw->location ? mb_substr(TextCleaner::plain($this->raw->location), 0, 250) : null,
            'remote_scope' => $this->eligibility->scope,
            'country_eligibility' => $this->eligibility->country,
            'eligibility_evidence' => $this->eligibility->evidence,
            'timezone_note' => $this->eligibility->timezoneNote,
            'requires_relocation' => $this->eligibility->requiresRelocation,
            'salary_status' => $this->salary ? 'disclosed' : 'undisclosed',
            'salary_origin' => $this->salaryOrigin,
            'salary_min' => $this->salary['min'] ?? null,
            'salary_max' => $this->salary['max'] ?? null,
            'salary_currency' => $this->salary['currency'] ?? null,
            'salary_period' => $this->salary['period'] ?? null,
            'monthly_usd_min' => $this->monthlyUsdMin,
            'monthly_usd_max' => $this->monthlyUsdMax,
            'budget_type' => $this->raw->budgetType,
            'budget_usd_min' => $this->budgetUsdMin,
            'budget_usd_max' => $this->budgetUsdMax,
            'client_signals' => $this->raw->clientSignals ?: null,
            'seniority' => $this->seniority,
            'required_years' => $this->requiredYears,
            'expires_at' => $this->raw->expiresAt,
        ];
    }
}
