<?php

namespace App\Services\Evaluation;

use App\Enums\CountryEligibility as Eligibility;
use App\Enums\RemoteScope as Scope;
use App\Models\CandidateProfile;
use App\Models\Opportunity;

/** Non-negotiable filters. These stay in code, so no model output can talk past them. */
class HardRules
{
    public function rejectionReason(Opportunity $opportunity, CandidateProfile $profile, array $compensation): ?string
    {
        if ($opportunity->requires_relocation) {
            return __('Requires relocation');
        }

        $excluded = $this->exclusionHit($opportunity, $profile);
        if ($excluded !== null) {
            return __('Excluded: :reason', ['reason' => __($excluded)]);
        }

        $inPerson = in_array($opportunity->remote_scope, [Scope::Hybrid, Scope::Onsite], true);

        if ($opportunity->country_eligibility === Eligibility::Excluded) {
            return $inPerson ? __('On-site or hybrid outside your country') : __('Remote role excludes your country');
        }

        if ($compensation['reject'] !== null) {
            return $compensation['reject'];
        }

        if ($inPerson) {
            return $this->inHomeCountryRejection($opportunity, $profile->compensation);
        }

        if ($opportunity->isFreelance() && mb_strlen($opportunity->description) < 40) {
            return __('Project scope too vague');
        }

        return null;
    }

    private function inHomeCountryRejection(Opportunity $opportunity, array $rules): ?string
    {
        $monthly = $opportunity->monthly_usd_max;

        if ($opportunity->remote_scope === Scope::Onsite) {
            return $monthly !== null && $monthly >= $rules['onsite_home_exceptional'] ? null : __('On-site in your country without exceptional pay');
        }

        return $monthly !== null && $monthly < $rules['full_time_floor'] ? __('Hybrid in your country below the salary floor') : null;
    }

    private function exclusionHit(Opportunity $opportunity, CandidateProfile $profile): ?string
    {
        $lead = implode(' ', [$opportunity->title, $opportunity->company, mb_substr($opportunity->description, 0, 800)]);
        $full = implode(' ', [$opportunity->title, $opportunity->company, $opportunity->location_text, $opportunity->description]);

        foreach ($profile->exclusions as $label => $rule) {
            $subject = $rule['scope'] === 'full' ? $full : $lead;
            if (preg_match('~'.$rule['pattern'].'~iu', $subject)) {
                return $label;
            }
        }

        return null;
    }
}
