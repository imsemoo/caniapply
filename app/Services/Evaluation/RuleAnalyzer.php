<?php

namespace App\Services\Evaluation;

use App\Enums\CountryEligibility as Eligibility;
use App\Enums\RemoteScope as Scope;
use App\Models\CandidateProfile;
use App\Models\Opportunity;
use App\Services\Normalization\SkillExtractor;

/** Scores what can be judged from the text alone. Works with no LLM configured. */
class RuleAnalyzer
{
    private const ESTABLISHED_LEVELS = ['intern' => 15, 'junior' => 40, 'mid' => 85, 'senior' => 100, 'lead' => 80, 'principal' => 55, 'manager' => 30, 'director' => 20];

    private const ENTRY_LEVELS = ['intern' => 70, 'junior' => 85, 'mid' => 35, 'senior' => 10, 'lead' => 5, 'principal' => 5, 'manager' => 5, 'director' => 5];

    private const SUSPICIOUS = [
        '/(?:registration|training|application|processing|onboarding) fee|رسوم (?:التسجيل|التدريب|التقديم)/iu' => 'Asks candidates to pay a fee',
        '/send (?:us )?(?:your )?(?:bank|passport|national id|ssn)/i' => 'Asks for personal documents up front',
        '/earn \$?\d[\d,]*\s*(?:per|a|\/)\s*(?:day|week)/i' => 'Promises fixed earnings',
        '/no experience (?:required|needed)/i' => 'Says no experience is needed',
        '/(?:contact|message|apply)[^.\n]{0,30}(?:whatsapp|telegram)/i' => 'Applications go through WhatsApp or Telegram',
        '/commission[\s-]only/i' => 'Commission-only pay',
        '/\bunpaid\b|بدون مقابل|غير مدفوع/iu' => 'Mentions unpaid work',
        '/equity[\s-]only/i' => 'Equity-only pay',
    ];

    private const CONCERNS = [
        '/(?:fluent|excellent|strong|native)[^.\n]{0,25}(?:spoken|verbal)|(?:spoken|verbal)[^.\n]{0,25}english|\bC1\b|\bC2\b/i' => 'Asks for strong spoken English',
        '/(?:record|submit|send|include)[^.\n]{0,25}(?:video|loom)/i' => 'May need a recorded video',
        '/exclusiv|no (?:other|parallel) (?:employment|commitments)/i' => 'May require exclusivity with your current job',
    ];

    public function __construct(private SkillExtractor $skills) {}

    public function analyze(Opportunity $opportunity, CandidateProfile $profile): RuleAnalysis
    {
        $track = $profile->track($opportunity->track);
        $technical = $this->technical($opportunity, $track);
        [$responsibilityScore, $responsibilities] = $this->responsibilities($opportunity->description, $track);

        $flags = $this->matchedLabels(self::SUSPICIOUS, $opportunity->description);
        if (! $opportunity->isFreelance() && ($opportunity->company === null || trim($opportunity->company) === '')) {
            $flags[] = __('Employer is not named');
        }

        return new RuleAnalysis(
            technical: $technical['score'],
            technicalNote: $technical['note'],
            titleConflict: $technical['conflict'],
            seniority: $this->seniority($opportunity, $track),
            responsibilities: $responsibilityScore,
            growth: $this->growth($opportunity, $profile),
            skillsMatched: $technical['matched'],
            skillsPartial: $technical['partial'],
            skillsMissing: $technical['missing'],
            skillsOptionalMissing: $technical['optional_missing'],
            responsibilityHits: $responsibilities,
            suspiciousFlags: $flags,
            concerns: $this->matchedLabels(self::CONCERNS, $opportunity->description),
            companyInfo: $this->companyInfo($opportunity),
        );
    }

    public function eligibilityScore(Opportunity $opportunity): int
    {
        if ($opportunity->country_eligibility === Eligibility::Excluded) {
            return 0;
        }

        return match ($opportunity->remote_scope) {
            Scope::Worldwide, Scope::SpecificCountries => $opportunity->country_eligibility === Eligibility::Eligible ? 100 : 60,
            Scope::MultiCountry => $opportunity->country_eligibility === Eligibility::Eligible ? 95 : 70,
            Scope::Mena => 90,
            Scope::Emea => 85,
            Scope::TimezoneBound => 60,
            Scope::Hybrid => 45,
            Scope::Onsite => 25,
            default => 55,
        };
    }

    private function technical(Opportunity $opportunity, array $track): array
    {
        $found = $this->skills->extract($opportunity->title, $opportunity->description);
        $tools = config('skills.tool_skills');
        $tiers = $track['skills'];

        $earned = 0.0;
        $possible = 0.0;
        $matched = $partial = $missing = [];

        foreach ($found['required'] as $skill) {
            $worth = in_array($skill, $tools, true) ? 0.5 : 1.0;
            $possible += $worth;

            if (in_array($skill, $tiers['primary'], true)) {
                $earned += $worth;
                $matched[] = $skill;
            } elseif (in_array($skill, $tiers['secondary'], true)) {
                $earned += $worth * 0.7;
                $matched[] = $skill;
            } elseif (in_array($skill, $tiers['light'], true)) {
                $earned += $worth * 0.4;
                $partial[] = $skill;
            } else {
                $missing[] = $skill;
            }
        }

        $known = array_merge($tiers['primary'], $tiers['secondary'], $tiers['light']);
        $optionalMissing = array_values(array_diff($found['optional'], $known));

        $inTitle = $this->skills->find($opportunity->title);
        $anchored = array_intersect($inTitle, $track['anchor_skills']) !== [];
        $conflict = ! $anchored && array_intersect($inTitle, config('skills.foreign_stacks')) !== [];

        if ($possible === 0.0) {
            return ['score' => 40, 'note' => __('The listing names no recognisable skills.'), 'conflict' => $conflict] + compact('matched', 'partial', 'missing') + ['optional_missing' => $optionalMissing];
        }

        $score = 100 * $earned / $possible + ($anchored ? 10 : 0);
        if ($conflict) {
            $score = min($score, 25);
        }
        // One or two recognised skills is too little evidence for a high score.
        if (! $anchored && count($found['required']) < 3) {
            $score = min($score, 70);
        }

        $note = __(':matched of :total required skills matched.', ['matched' => count($matched), 'total' => count($found['required'])]);

        return ['score' => (int) round(min($score, 100)), 'note' => $note, 'conflict' => $conflict] + compact('matched', 'partial', 'missing') + ['optional_missing' => $optionalMissing];
    }

    private function seniority(Opportunity $opportunity, array $track): int
    {
        if ($opportunity->isFreelance()) {
            return 85;
        }

        $experience = $track['experience_years'];
        $levels = $experience >= 3 ? self::ESTABLISHED_LEVELS : self::ENTRY_LEVELS;
        $levelScore = $levels[$opportunity->seniority] ?? ($experience >= 3 ? 75 : 40);

        $required = $opportunity->required_years;
        if ($required === null) {
            return $levelScore;
        }

        $yearsScore = match (true) {
            $required <= $experience => 100,
            $required <= $experience + 2 => 60,
            default => 15,
        };

        return min($levelScore, $yearsScore);
    }

    /** @return array{0: int, 1: string[]} */
    private function responsibilities(string $text, array $track): array
    {
        $hits = [];
        foreach ($track['responsibilities'] as $label => $pattern) {
            if (preg_match('~'.$pattern.'~iu', $text)) {
                $hits[] = __($label);
            }
        }

        return [min(100, 40 + 15 * count($hits)), $hits];
    }

    private function growth(Opportunity $opportunity, CandidateProfile $profile): int
    {
        $score = 55;

        if (in_array($opportunity->seniority, ['senior', 'lead'], true)) {
            $score += 10;
        }
        if (preg_match('/remote[\s-]first|ownership|mentorship|learning budget|product company|startup/i', $opportunity->description)) {
            $score += 15;
        }
        if (($opportunity->monthly_usd_max ?? 0) >= $profile->compensation['preferred_max']) {
            $score += 15;
        }

        return min($score, 100);
    }

    private function matchedLabels(array $patterns, string $text): array
    {
        $labels = [];
        foreach ($patterns as $pattern => $label) {
            if (preg_match($pattern, $text)) {
                $labels[] = __($label);
            }
        }

        return $labels;
    }

    private function companyInfo(Opportunity $opportunity): ?string
    {
        foreach (preg_split('/\n+/', $opportunity->description) as $paragraph) {
            if (mb_strlen($paragraph) < 60) {
                continue;
            }

            $namesCompany = $opportunity->company && mb_stripos($paragraph, $opportunity->company) !== false;
            if ($namesCompany || preg_match('/^(we are|we\'re|about us|our (company|mission))/i', $paragraph)) {
                return mb_substr($paragraph, 0, 320);
            }
        }

        return null;
    }
}
