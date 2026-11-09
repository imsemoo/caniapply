<?php

namespace App\Services\Applications;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\Opportunity;
use Illuminate\Support\Facades\Cache;

/** Nudges the ranking towards what you actually save or reject. Hard rules never move. */
class PreferenceLearner
{
    private const MIN_DECISIONS = 6;

    private const MIN_FEATURE_DECISIONS = 3;

    private const FULL_EFFECT = 1.4;

    /** @return array{points: int, reasons: string[]} */
    public function adjustmentFor(Opportunity $opportunity, array $skills, CandidateProfile $profile): array
    {
        $limit = (int) ($profile->thresholds['learning_max_points'] ?? 0);
        $model = $limit > 0 ? $this->model() : [];

        $signals = [];
        foreach ($this->features($opportunity, $skills) as $feature => $label) {
            if (isset($model[$feature])) {
                $signals[$label] = $model[$feature];
            }
        }

        if ($signals === []) {
            return ['points' => 0, 'reasons' => []];
        }

        $mean = array_sum($signals) / count($signals);
        $points = (int) round(max(-1, min(1, $mean / self::FULL_EFFECT)) * $limit);

        if ($points === 0) {
            return ['points' => 0, 'reasons' => []];
        }

        arsort($signals);
        $strongest = $points > 0 ? array_slice($signals, 0, 2, true) : array_slice(array_reverse($signals, true), 0, 2, true);

        $reasons = [];
        foreach ($strongest as $label => $weight) {
            if ($weight > 0 && $points > 0) {
                $reasons[] = __('You usually save jobs with :feature', ['feature' => $label]);
            } elseif ($weight < 0 && $points < 0) {
                $reasons[] = __('You usually reject jobs with :feature', ['feature' => $label]);
            }
        }

        return ['points' => $points, 'reasons' => $reasons];
    }

    /** @return array<string, float> log-odds of keeping a job with each feature */
    private function model(): array
    {
        return Cache::remember('preference-model', now()->addMinutes(10), function () {
            $decisions = Application::query()
                ->with('opportunity:id,analysis,remote_scope,employment_type,seniority')
                ->whereIn('status', array_map(fn (ApplicationStatus $s) => $s->value, ApplicationStatus::cases()))
                ->get();

            if ($decisions->count() < self::MIN_DECISIONS) {
                return [];
            }

            $counts = [];
            $totals = ['yes' => 0, 'no' => 0];

            foreach ($decisions as $decision) {
                // Rejected after applying is the employer's decision, not yours.
                if ($decision->status === ApplicationStatus::Rejected && $decision->applied_on !== null) {
                    continue;
                }

                $job = $decision->opportunity;
                $side = $decision->status === ApplicationStatus::Rejected ? 'no' : 'yes';
                $totals[$side]++;
                $skills = [...($job->analysis['skills_matched'] ?? []), ...($job->analysis['skills_partial'] ?? []), ...($job->analysis['skills_missing'] ?? [])];

                foreach (array_keys($this->features($job, $skills)) as $feature) {
                    $counts[$feature][$side] = ($counts[$feature][$side] ?? 0) + 1;
                }
            }

            // Measured against your overall habit, so saving everything teaches nothing.
            $baseline = log(($totals['yes'] + 1) / ($totals['no'] + 1));

            $model = [];
            foreach ($counts as $feature => $count) {
                $yes = $count['yes'] ?? 0;
                $no = $count['no'] ?? 0;

                if ($yes + $no >= self::MIN_FEATURE_DECISIONS) {
                    $model[$feature] = log(($yes + 1) / ($no + 1)) - $baseline;
                }
            }

            return $model;
        });
    }

    /** @return array<string, string> feature key => what to call it */
    private function features(Opportunity $job, array $skills): array
    {
        $features = [];

        foreach (array_unique($skills) as $skill) {
            $features['skill:'.$skill] = $skill;
        }

        $features['scope:'.$job->remote_scope->value] = $job->remote_scope->label();
        $features['type:'.$job->employment_type->value] = $job->employment_type->label();

        if ($job->seniority !== null) {
            $features['level:'.$job->seniority] = __(ucfirst($job->seniority));
        }

        return $features;
    }
}
