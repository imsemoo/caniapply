<?php

namespace App\Services\Evaluation;

use App\Enums\EmploymentType;
use App\Models\CandidateProfile;
use App\Models\Opportunity;
use App\Services\Normalization\CurrencyConverter;

class CompensationAssessor
{
    public function __construct(private CurrencyConverter $currency) {}

    /** @return array{score: int, note: string, reject: ?string, effective_hourly: ?float, concern: ?string} */
    public function assess(Opportunity $opportunity, CandidateProfile $profile, bool $longTerm = false): array
    {
        return $opportunity->isFreelance()
            ? $this->freelance($opportunity, $profile->compensation, $longTerm)
            : $this->employment($opportunity, $profile->compensation);
    }

    private function employment(Opportunity $opportunity, array $rules): array
    {
        if ($opportunity->salary_status !== 'disclosed') {
            return $this->result($rules['undisclosed_score'], __('Salary not disclosed. Neutral score, kept for manual review.'));
        }

        $floor = $rules['full_time_floor'];
        $high = $opportunity->monthly_usd_max;
        $low = $opportunity->monthly_usd_min ?? $high;

        if ($high === null) {
            return $this->rateOnly($opportunity, $rules);
        }

        $isFullTime = in_array($opportunity->employment_type, [EmploymentType::FullTime, EmploymentType::Unknown], true);

        if ($isFullTime && $high < $floor) {
            return $this->result(0, __('About $:amount/month, below the $:floor floor.', ['amount' => number_format($high), 'floor' => number_format($floor)]), reject: __('Full-time pay below the $:floor floor', ['floor' => number_format($floor)]));
        }

        if ($low > 0 && $high / $low >= 3) {
            return $this->result($rules['undisclosed_score'], __('The pay range is too wide to judge: about $:low to $:high a month.', ['low' => number_format($low), 'high' => number_format($high)]));
        }

        $middle = ($low + $high) / 2;
        $score = $this->payScale($middle, $rules);
        $note = __('About $:amount/month.', ['amount' => number_format($middle)]);

        if ($high < $floor) {
            return $this->result(25, $note.' '.__('Below the monthly floor for a :type role.', ['type' => $opportunity->employment_type->label()]));
        }

        $concern = $low < $floor ? __('The range starts below your floor (about $:amount/month). Confirm the offer is at the upper end.', ['amount' => number_format($low)]) : null;

        return $this->result($score, $note, concern: $concern);
    }

    /** A contract quoted per hour with no stated hours: judge the rate, assert no monthly total. */
    private function rateOnly(Opportunity $opportunity, array $rules): array
    {
        $middle = ($opportunity->salary_min + $opportunity->salary_max) / 2;
        $perHour = match ($opportunity->salary_period) {
            'hour' => $middle,
            'day' => $middle / 8,
            'week' => $middle / 40,
            default => null,
        };
        $usd = $perHour !== null ? $this->currency->toUsd($perHour, $opportunity->salary_currency) : null;

        if ($usd === null) {
            return $this->result($rules['undisclosed_score'], __('Rate given, but it could not be converted.'));
        }

        return $this->result($this->rateScale($usd, $rules), __('About $:amount/hour. Monthly equivalent not calculated because hours or duration are not stated.', ['amount' => number_format($usd, 0)]));
    }

    private function freelance(Opportunity $opportunity, array $rules, bool $longTerm): array
    {
        $bonus = $longTerm ? 5 : 0;
        $rate = $this->effectiveHourly($opportunity);

        if ($rate === null) {
            $note = $opportunity->budget_usd_max === null
                ? __('Budget not stated.')
                : __('Budget stated, but working hours are not estimated, so profitability is unknown.');

            // Even a modest build rarely fits under this; treat it as a weak signal.
            $tiny = $opportunity->budget_usd_max !== null && $opportunity->budget_usd_max < 100;

            return $this->result(min(100, ($tiny ? 30 : $rules['freelance_unknown_hours_score']) + $bonus), $note);
        }

        if ($rate < $rules['freelance_hard_min_hourly']) {
            return $this->result(0, __('About $:amount/hour effective.', ['amount' => number_format($rate, 1)]), reject: __('Unrealistic budget for the scope'), hourly: $rate);
        }

        return $this->result(
            min(100, $this->rateScale($rate, $rules) + $bonus),
            __('About $:amount/hour effective.', ['amount' => number_format($rate, 0)]),
            hourly: $rate,
        );
    }

    private function effectiveHourly(Opportunity $opportunity): ?float
    {
        if ($opportunity->budget_usd_max === null) {
            return null;
        }

        $middle = (($opportunity->budget_usd_min ?? $opportunity->budget_usd_max) + $opportunity->budget_usd_max) / 2;

        if ($opportunity->budget_type === 'hourly') {
            return $middle;
        }

        return $opportunity->estimated_hours > 0 ? round($middle / $opportunity->estimated_hours, 2) : null;
    }

    private function payScale(float $monthly, array $rules): int
    {
        return (int) round(match (true) {
            $monthly >= $rules['preferred_max'] => 100,
            $monthly >= $rules['preferred_min'] => $this->between($monthly, $rules['preferred_min'], $rules['preferred_max'], 75, 95),
            $monthly >= $rules['full_time_floor'] => $this->between($monthly, $rules['full_time_floor'], $rules['preferred_min'], 60, 75),
            default => 25,
        });
    }

    private function rateScale(float $rate, array $rules): int
    {
        return (int) round(match (true) {
            $rate >= $rules['freelance_target_hourly'] => 100,
            $rate >= $rules['freelance_min_hourly'] => $this->between($rate, $rules['freelance_min_hourly'], $rules['freelance_target_hourly'], 60, 95),
            default => 40,
        });
    }

    private function between(float $value, float $from, float $to, float $scoreFrom, float $scoreTo): float
    {
        return $scoreFrom + ($value - $from) / max($to - $from, 1) * ($scoreTo - $scoreFrom);
    }

    private function result(int $score, string $note, ?string $reject = null, ?float $hourly = null, ?string $concern = null): array
    {
        return ['score' => $score, 'note' => $note, 'reject' => $reject, 'effective_hourly' => $hourly, 'concern' => $concern];
    }
}
