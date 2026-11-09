<?php

namespace App\Services\Normalization;

use App\Enums\EmploymentType;
use App\Models\CandidateProfile;
use App\Sources\RawListing;

class ListingNormalizer
{
    private const STATED_TYPE = '/\b(?:full[\s-]?time|part[\s-]?time|contract (?:role|position|basis)|fixed[\s-]term)\b|دوام كامل|دوام جزئي/iu';

    public function __construct(
        private SalaryParser $salaries,
        private CurrencyConverter $currency,
        private EligibilityDetector $eligibility,
        private SeniorityDetector $seniority,
        private RelevanceFilter $relevance,
    ) {}

    /** Null when the listing has no bearing on this candidate. */
    public function normalize(RawListing $raw, CandidateProfile $profile, ?string $forcedTrack = null): ?NormalizedListing
    {
        $description = TextCleaner::plain($raw->description);
        $title = TextCleaner::plain($raw->title);

        if ($title === '') {
            return null;
        }

        $track = $forcedTrack ?? $this->relevance->trackFor($title, $description, $raw->isFreelance, $profile);
        if ($track === null) {
            return null;
        }

        $employment = $this->employmentType($raw, $title, $description);
        [$salary, $origin] = $this->salary($raw, $description);
        [$monthlyMin, $monthlyMax] = $salary ? $this->monthlyUsd($salary, $employment, $description) : [null, null];
        $level = $this->seniority->detect($title, $description, $raw->tags);

        return new NormalizedListing(
            raw: $raw,
            track: $track,
            description: $description,
            titleNorm: TextCleaner::normalizedTitle($title),
            companyNorm: TextCleaner::normalizedCompany($raw->company),
            employmentType: $employment,
            eligibility: $this->eligibility->detect($raw, $description),
            salary: $salary,
            salaryOrigin: $origin,
            monthlyUsdMin: $monthlyMin,
            monthlyUsdMax: $monthlyMax,
            budgetUsdMin: $this->budgetUsd($raw->budgetMin, $raw->budgetCurrency),
            budgetUsdMax: $this->budgetUsd($raw->budgetMax, $raw->budgetCurrency),
            seniority: $level['level'],
            requiredYears: $level['years'],
            contentHash: hash('sha256', implode('|', [$title, $raw->company, $raw->location, json_encode($salary), $description])),
        );
    }

    private function employmentType(RawListing $raw, string $title, string $description): EmploymentType
    {
        if ($raw->isFreelance) {
            return EmploymentType::Freelance;
        }

        $type = EmploymentType::fromText($raw->employmentType);
        if ($type === EmploymentType::Unknown) {
            $type = EmploymentType::fromText($title);
        }
        if ($type === EmploymentType::Unknown && preg_match(self::STATED_TYPE, mb_substr($description, 0, 800), $match)) {
            $type = EmploymentType::fromText($match[0]);
        }

        // Only marketplace projects are judged by budget; a board post has a rate to check.
        return $type === EmploymentType::Freelance ? EmploymentType::Contract : $type;
    }

    /** @return array{0: ?array, 1: ?string} */
    private function salary(RawListing $raw, string $description): array
    {
        if ($raw->isFreelance) {
            return [null, null];
        }

        if (! $raw->salaryIsEstimate && ($raw->salaryMin || $raw->salaryMax) && $raw->salaryCurrency) {
            $period = $this->period($raw->salaryPeriod);
            if ($period !== null) {
                $min = (float) ($raw->salaryMin ?: $raw->salaryMax);
                $max = (float) ($raw->salaryMax ?: $raw->salaryMin);

                return [['min' => $min, 'max' => $max, 'currency' => strtoupper($raw->salaryCurrency), 'period' => $period, 'quote' => null], 'source'];
            }
        }

        $parsed = $this->salaries->parse($description);

        return $parsed ? [$parsed, 'post text'] : [null, null];
    }

    private function period(?string $period): ?string
    {
        return match (strtolower(trim((string) $period))) {
            'year', 'yearly', 'annual', 'annually', 'per-year', 'yr' => 'year',
            'month', 'monthly', 'per-month' => 'month',
            'week', 'weekly', 'per-week' => 'week',
            'day', 'daily', 'per-day' => 'day',
            'hour', 'hourly', 'per-hour' => 'hour',
            default => null,
        };
    }

    /** @return array{0: ?float, 1: ?float} */
    public function monthlyUsd(array $salary, EmploymentType $employment, string $description): array
    {
        $factor = $this->monthlyFactor($salary['period'], $employment, $description);
        if ($factor === null) {
            return [null, null];
        }

        $min = $this->currency->toUsd($salary['min'] * $factor, $salary['currency']);
        $max = $this->currency->toUsd($salary['max'] * $factor, $salary['currency']);

        return [$min !== null ? round($min, 2) : null, $max !== null ? round($max, 2) : null];
    }

    /**
     * Hourly, daily and weekly rates only become a monthly figure when the
     * working pattern is known. For contracts the brief forbids assuming it.
     */
    private function monthlyFactor(string $period, EmploymentType $employment, string $description): ?float
    {
        if ($period === 'year') {
            return 1 / 12;
        }
        if ($period === 'month') {
            return 1.0;
        }

        $hoursPerWeek = $this->statedHoursPerWeek($description);
        $hoursPerWeek ??= $employment === EmploymentType::FullTime || preg_match('/full[\s-]?time\s+(?:contract|contractor|role|position|basis)/i', $description) ? 40 : null;

        if ($hoursPerWeek === null) {
            return null;
        }

        return match ($period) {
            'hour' => $hoursPerWeek * 4.33,
            'day' => ($hoursPerWeek / 8) * 4.33,
            'week' => 4.33,
        };
    }

    private function statedHoursPerWeek(string $description): ?int
    {
        if (! preg_match('/[^.\n]{0,40}\b(\d{1,2})\s*(?:hours|hrs?)\s*(?:per|\/|a)\s*week[^.\n]{0,20}/i', $description, $match)) {
            return null;
        }

        $hours = (int) $match[1];

        // "5 hours a week of overlap" describes availability, not the working pattern.
        return $hours >= 10 && $hours <= 60 && stripos($match[0], 'overlap') === false ? $hours : null;
    }

    private function budgetUsd(?float $amount, ?string $currency): ?float
    {
        if ($amount === null || $amount <= 0) {
            return null;
        }

        $usd = $this->currency->toUsd($amount, $currency);

        return $usd !== null ? round($usd, 2) : null;
    }
}
