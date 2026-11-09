<?php

namespace App\Services\Discovery;

use App\Enums\RemoteScope;
use App\Models\Opportunity;
use App\Services\Normalization\EligibilityDetector;
use App\Sources\RawListing;

/** Decides eligibility again for every open job after the candidate's country changes. */
class EligibilityRefresher
{
    public function __construct(private EligibilityDetector $detector) {}

    public function run(): int
    {
        $count = 0;

        Opportunity::query()->active()->each(function (Opportunity $opportunity) use (&$count) {
            $finding = $this->detector->detect(new RawListing(
                externalId: (string) $opportunity->id,
                url: (string) $opportunity->primary_url,
                title: $opportunity->title,
                description: $opportunity->description,
                location: $opportunity->location_text,
                remote: $opportunity->remote_scope !== RemoteScope::Onsite,
                isFreelance: $opportunity->isFreelance(),
            ), $opportunity->description);

            $opportunity->update([
                'remote_scope' => $finding->scope,
                'country_eligibility' => $finding->country,
                'eligibility_evidence' => $finding->evidence,
                'timezone_note' => $finding->timezoneNote,
                'requires_relocation' => $finding->requiresRelocation,
                'needs_evaluation' => true,
            ]);

            $count++;
        });

        return $count;
    }
}
