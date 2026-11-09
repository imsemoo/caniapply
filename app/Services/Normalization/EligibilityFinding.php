<?php

namespace App\Services\Normalization;

use App\Enums\CountryEligibility;
use App\Enums\RemoteScope;

final class EligibilityFinding
{
    public function __construct(
        public readonly RemoteScope $scope,
        public readonly CountryEligibility $country,
        public readonly ?string $evidence = null,
        public readonly ?string $timezoneNote = null,
        public readonly bool $requiresRelocation = false,
    ) {}
}
