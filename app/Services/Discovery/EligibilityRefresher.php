<?php

namespace App\Services\Discovery;

use App\Enums\CountryEligibility;
use App\Enums\RemoteScope;
use App\Models\Opportunity;
use App\Services\Normalization\EligibilityDetector;
use App\Sources\RawListing;

/** Decides eligibility again for every open job after the candidate's country changes. */
class EligibilityRefresher
{
    private const UNCONFIRMED = 'The source shows only part of its country list, so your country is not confirmed yet';

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

            // "Germany, France and others" may well include the new country. The source's next search settles it.
            $cutOff = $finding->country === CountryEligibility::Excluded
                && str_ends_with((string) $opportunity->location_text, ' and others')
                && str_starts_with((string) $finding->evidence, 'Listing location:');

            $opportunity->update([
                'remote_scope' => $finding->scope,
                'country_eligibility' => $cutOff ? CountryEligibility::Unknown : $finding->country,
                'eligibility_evidence' => $cutOff ? self::UNCONFIRMED : $finding->evidence,
                'timezone_note' => $finding->timezoneNote,
                'requires_relocation' => $finding->requiresRelocation,
                'needs_evaluation' => true,
            ]);

            $count++;
        });

        return $count;
    }
}
