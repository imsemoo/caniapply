<?php

namespace App\Services\Evaluation;

use App\Enums\Bucket;
use App\Enums\CountryEligibility as Eligibility;
use App\Enums\EmploymentType;
use App\Enums\RemoteScope as Scope;
use App\Models\CandidateProfile;
use App\Models\Opportunity;
use App\Services\Applications\PreferenceLearner;
use App\Services\Llm\LlmFailed;
use App\Support\HomeCountry;
use App\Services\Llm\LlmGateway;
use App\Services\Normalization\ListingNormalizer;
use App\Services\Normalization\SalaryParser;
use App\Services\Notifications\OperatorAlerts;
use Illuminate\Support\Facades\Log;

class OpportunityEvaluator
{
    private const MAX_LLM_LIFT = 30;

    private const MAX_PLAUSIBLE_HOURLY = 150;

    public function __construct(
        private RuleAnalyzer $rules,
        private HardRules $hardRules,
        private CompensationAssessor $compensation,
        private LlmGateway $llm,
        private SalaryParser $salaries,
        private ListingNormalizer $normalizer,
        private OperatorAlerts $operatorAlerts,
        private PreferenceLearner $preferences,
    ) {}

    public function evaluate(Opportunity $opportunity): Opportunity
    {
        $profile = CandidateProfile::current();
        $rule = $this->rules->analyze($opportunity, $profile);
        $compensation = $this->compensation->assess($opportunity, $profile);
        $factors = $this->ruleFactors($rule, $compensation, $this->rules->eligibilityScore($opportunity));

        $llm = null;
        $llmError = null;
        $canAsk = $this->hardRules->rejectionReason($opportunity, $profile, $compensation) === null
            && $this->weighted($factors, $profile) >= config('hunter.llm.min_prescore')
            && $this->llm->isAvailable();

        if ($canAsk) {
            try {
                $llm = $this->llm->json(JobAnalysisPrompt::system(), JobAnalysisPrompt::user($opportunity, $profile), JobAnalysisPrompt::schema());
                $this->applyVerifiedFacts($opportunity, $llm, $rule);
                $compensation = $this->compensation->assess($opportunity, $profile, (bool) ($llm['freelance']['long_term_potential'] ?? false));
                $factors = $this->llmFactors($rule, $llm, $compensation, $this->rules->eligibilityScore($opportunity));
            } catch (LlmFailed $e) {
                $llm = null;
                $llmError = $e->getMessage();
                Log::warning('LLM evaluation failed; using rules', ['opportunity' => $opportunity->id, 'error' => $llmError]);
                $this->operatorAlerts->llmFailing($llmError);
            }
        }

        return $this->store($opportunity, $profile, $rule, $compensation, $factors, $llm, $llmError);
    }

    private function ruleFactors(RuleAnalysis $rule, array $compensation, int $eligibility): array
    {
        return [
            'technical' => ['score' => $rule->technical, 'note' => $rule->technicalNote],
            'seniority' => ['score' => $rule->seniority, 'note' => null],
            'compensation' => ['score' => $compensation['score'], 'note' => $compensation['note']],
            'eligibility' => ['score' => $eligibility, 'note' => null],
            'responsibilities' => ['score' => $rule->responsibilities, 'note' => null],
            'growth' => ['score' => $rule->growth, 'note' => null],
        ];
    }

    private function llmFactors(RuleAnalysis $rule, array $llm, array $compensation, int $eligibility): array
    {
        $factors = $this->ruleFactors($rule, $compensation, $eligibility);
        $technical = $this->clamp($llm['technical_fit'] ?? $rule->technical);

        // Two cases where the text-based reading outranks a generous model.
        if ($rule->titleConflict) {
            $technical = min($technical, $rule->technical + 20);
        }
        $seniority = $this->clamp($llm['seniority_fit'] ?? $rule->seniority);
        if ($rule->seniority <= 15) {
            $seniority = min($seniority, 30);
        }

        // The model may refine the text-based reading, not replace it.
        $factors['technical']['score'] = min($technical, $rule->technical + self::MAX_LLM_LIFT);
        $factors['seniority']['score'] = min($seniority, $rule->seniority + self::MAX_LLM_LIFT);
        $factors['responsibilities']['score'] = min($this->clamp($llm['responsibilities_fit'] ?? $rule->responsibilities), $rule->responsibilities + self::MAX_LLM_LIFT);
        $factors['growth']['score'] = min($this->clamp($llm['growth_potential'] ?? $rule->growth), $rule->growth + self::MAX_LLM_LIFT);

        return $factors;
    }

    /** Model output changes a stored fact only if its quote is really in the listing. */
    private function applyVerifiedFacts(Opportunity $opportunity, array $llm, RuleAnalysis $rule): void
    {
        $text = $this->comparable($opportunity->title."\n".$opportunity->location_text."\n".$opportunity->description);
        $isQuoted = fn (?string $quote) => $quote !== null && mb_strlen(trim($quote)) >= 4 && str_contains($text, $this->comparable($quote));

        $status = $llm['eligibility']['country_status'] ?? 'unknown';
        $quote = $llm['eligibility']['quote'] ?? null;

        if ($status === 'excluded' && $isQuoted($quote)) {
            $opportunity->country_eligibility = Eligibility::Excluded;
            $opportunity->eligibility_evidence = 'Post says: "'.$quote.'"';
            if ($opportunity->remote_scope === Scope::Worldwide) {
                $opportunity->remote_scope = Scope::SpecificCountries;
            }
        } elseif ($status === 'eligible' && $opportunity->country_eligibility === Eligibility::Unknown && $isQuoted($quote) && HomeCountry::current()->isCoveredBy($quote)) {
            $opportunity->country_eligibility = Eligibility::Eligible;
            $opportunity->eligibility_evidence = 'Post says: "'.$quote.'"';
        }

        if (($llm['relocation_required']['required'] ?? false) && $isQuoted($llm['relocation_required']['quote'] ?? null)) {
            $opportunity->requires_relocation = true;
        }

        $this->applySalaryQuote($opportunity, $llm['salary_quote'] ?? null, $isQuoted);

        if ($opportunity->employment_type === EmploymentType::Unknown) {
            $opportunity->employment_type = EmploymentType::tryFrom($llm['employment_type'] ?? '') ?? EmploymentType::Unknown;
        }

        $hours = $llm['freelance']['estimated_hours'] ?? null;
        if ($opportunity->isFreelance() && is_numeric($hours) && $hours >= 1 && $hours <= 3000 && $this->hoursArePlausible($opportunity, (float) $hours)) {
            $opportunity->estimated_hours = (float) $hours;
        }
    }

    /** An estimate implying an absurd rate is more likely a manipulated listing than a good deal. */
    private function hoursArePlausible(Opportunity $opportunity, float $hours): bool
    {
        return $opportunity->budget_type !== 'fixed'
            || $opportunity->budget_usd_max === null
            || $opportunity->budget_usd_max / $hours <= self::MAX_PLAUSIBLE_HOURLY;
    }

    private function applySalaryQuote(Opportunity $opportunity, ?string $quote, callable $isQuoted): void
    {
        if ($opportunity->salary_status === 'disclosed' || $opportunity->isFreelance() || ! $isQuoted($quote)) {
            return;
        }

        // The figures come from parsing the quote, never from the model's own numbers.
        $parsed = $this->salaries->parse('Salary: '.$quote);
        if ($parsed === null) {
            return;
        }

        [$min, $max] = $this->normalizer->monthlyUsd($parsed, $opportunity->employment_type, $opportunity->description);

        $opportunity->forceFill([
            'salary_status' => 'disclosed',
            'salary_origin' => 'post text (found by AI, quote verified)',
            'salary_min' => $parsed['min'],
            'salary_max' => $parsed['max'],
            'salary_currency' => $parsed['currency'],
            'salary_period' => $parsed['period'],
            'monthly_usd_min' => $min,
            'monthly_usd_max' => $max,
        ]);
    }

    private function store(Opportunity $opportunity, CandidateProfile $profile, RuleAnalysis $rule, array $compensation, array $factors, ?array $llm, ?string $llmError): Opportunity
    {
        $reason = $this->hardRules->rejectionReason($opportunity, $profile, $compensation);

        if ($reason === null && $llm !== null && ($llm['freelance']['scope_clarity'] ?? null) === 'vague' && $opportunity->isFreelance()) {
            $reason = __('Project scope too vague');
        }

        $score = $reason !== null ? min($this->weighted($factors, $profile), 59) : $this->weighted($factors, $profile);
        $thresholds = $profile->thresholds;

        $preference = $reason === null
            ? $this->preferences->adjustmentFor($opportunity, [...$rule->skillsMatched, ...$rule->skillsPartial, ...$rule->skillsMissing], $profile)
            : ['points' => 0, 'reasons' => []];
        // Decisions reorder what already qualifies; they cannot lift a job over the reject line.
        if ($score < $thresholds['low']) {
            $preference = ['points' => min(0, $preference['points']), 'reasons' => []];
        }
        $score = max(0, min(100, $score + $preference['points']));

        // A project whose profitability cannot be worked out never interrupts you.
        if ($opportunity->isFreelance() && $compensation['effective_hourly'] === null) {
            $score = min($score, $thresholds['immediate'] - 6);
        }

        if ($reason === null && $score < $thresholds['low']) {
            $reason = __('Match score below :score', ['score' => $thresholds['low']]);
        }

        foreach ($factors as $key => $factor) {
            $factors[$key]['weight'] = $profile->scoring_weights[$key];
        }

        $suspicious = $rule->suspiciousFlags;
        $llmQuote = $llm['employer_suspicious']['quote'] ?? null;
        if (($llm['employer_suspicious']['suspicious'] ?? false) && $llmQuote !== null && str_contains($this->comparable($opportunity->description), $this->comparable($llmQuote))) {
            $suspicious[] = __('Employer looks suspicious: ":quote"', ['quote' => mb_substr($llmQuote, 0, 160)]);
        }

        $opportunity->fill([
            'score' => $score,
            'tier' => $this->tier($score, $thresholds),
            'bucket' => $this->bucket($opportunity, $reason, $suspicious),
            'reject_reason' => $reason,
            'factors' => $factors,
            'effective_hourly_usd' => $compensation['effective_hourly'],
            'analysis' => $this->analysis($opportunity, $rule, $compensation, $llm, $suspicious, $score, $thresholds, $llmError) + ['preference' => $preference],
            'evaluated_by' => $llm !== null ? $this->llm->engineLabel() : 'rules',
            'evaluated_at' => now(),
            'needs_evaluation' => false,
        ])->save();

        return $opportunity;
    }

    private function bucket(Opportunity $opportunity, ?string $reason, array $suspicious): Bucket
    {
        return match (true) {
            $reason !== null => Bucket::Rejected,
            $suspicious !== [] => Bucket::Flagged,
            ! $opportunity->isFreelance() && $opportunity->salary_status !== 'disclosed' => Bucket::Undisclosed,
            default => Bucket::Qualified,
        };
    }

    private function analysis(Opportunity $opportunity, RuleAnalysis $rule, array $compensation, ?array $llm, array $suspicious, int $score, array $thresholds, ?string $llmError): array
    {
        $concerns = array_merge($rule->concerns, array_map(fn (string $s) => __(':skill is not in your profile', ['skill' => $s]), array_slice($rule->skillsMissing, 0, 5)));

        if ($compensation['concern'] !== null) {
            $concerns[] = $compensation['concern'];
        }
        if ($opportunity->timezone_note !== null && str_starts_with($opportunity->timezone_note, 'Needs')) {
            $concerns[] = $opportunity->timezoneLabel();
        }
        if ($opportunity->country_eligibility === Eligibility::Unknown) {
            $concerns[] = __('The listing does not confirm that candidates in your country can apply');
        }

        $whyMatches = $this->ruleReasons($opportunity, $rule, $compensation);

        return [
            'why_matches' => $whyMatches,
            'why_apply' => $this->strings($llm['why_apply'] ?? $whyMatches),
            'concerns' => array_values(array_unique([...$this->strings($llm['concerns'] ?? []), ...$concerns])),
            'red_flags' => array_values(array_unique([...$suspicious, ...$this->strings($llm['red_flags'] ?? [])])),
            'skills_matched' => $this->strings($llm['skills_matched'] ?? $rule->skillsMatched),
            'skills_partial' => $rule->skillsPartial,
            'skills_missing' => $this->strings($llm['skills_missing'] ?? $rule->skillsMissing),
            'skills_optional_missing' => $rule->skillsOptionalMissing,
            'responsibility_hits' => $rule->responsibilityHits,
            'company_info' => is_string($llm['company_summary'] ?? null) ? $llm['company_summary'] : $rule->companyInfo,
            'recommendation' => is_string($llm['recommendation_text'] ?? null) ? $llm['recommendation_text'] : $this->ruleRecommendation($score, $thresholds),
            'hours_basis' => is_string($llm['freelance']['hours_basis'] ?? null) ? $llm['freelance']['hours_basis'] : null,
            'llm_error' => $llmError,
        ];
    }

    private function ruleReasons(Opportunity $opportunity, RuleAnalysis $rule, array $compensation): array
    {
        $reasons = [];

        if ($rule->skillsMatched !== []) {
            $reasons[] = __('Skills you have: :skills', ['skills' => implode(', ', array_slice($rule->skillsMatched, 0, 6))]);
        }
        foreach (array_slice($rule->responsibilityHits, 0, 2) as $hit) {
            $reasons[] = __('Work you have done: :work', ['work' => $hit]);
        }
        if ($opportunity->country_eligibility === Eligibility::Eligible) {
            $reasons[] = __('Open to candidates in your country');
        }
        if ($compensation['score'] >= 75) {
            $reasons[] = __('Pay is in your preferred range.').' '.$compensation['note'];
        }

        return $reasons;
    }

    private function ruleRecommendation(int $score, array $thresholds): string
    {
        return match (true) {
            $score >= $thresholds['exceptional'] => __('Exceptional match. Apply immediately.'),
            $score >= $thresholds['strong'] => __('Strong match. Worth applying soon.'),
            $score >= $thresholds['review'] => __('Worth reviewing before you decide.'),
            $score >= $thresholds['low'] => __('Low priority. Apply only if nothing better is open.'),
            default => __('Skip.'),
        };
    }

    private function tier(int $score, array $thresholds): string
    {
        return match (true) {
            $score >= $thresholds['exceptional'] => 'Exceptional Match',
            $score >= $thresholds['strong'] => 'Strong Match',
            $score >= $thresholds['review'] => 'Worth Reviewing',
            $score >= $thresholds['low'] => 'Low Priority',
            default => 'Reject',
        };
    }

    private function weighted(array $factors, CandidateProfile $profile): int
    {
        $total = 0;
        $weightSum = 0;

        foreach ($profile->scoring_weights as $key => $weight) {
            $total += $factors[$key]['score'] * $weight;
            $weightSum += $weight;
        }

        return $weightSum > 0 ? (int) round($total / $weightSum) : 0;
    }

    private function clamp(mixed $value): int
    {
        return max(0, min(100, (int) $value));
    }

    private function strings(mixed $items): array
    {
        return is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
    }

    private function comparable(string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim(str_replace(["\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}"], ["'", "'", '"', '"'], $text))));
    }
}
