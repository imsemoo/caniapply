<?php

namespace App\Enums;

enum RemoteScope: string
{
    case Worldwide = 'worldwide';
    case MultiCountry = 'multi_country';
    case Mena = 'mena';
    case Emea = 'emea';
    case Europe = 'europe';
    case UnitedStates = 'us';
    case SpecificCountries = 'specific_countries';
    case TimezoneBound = 'timezone_bound';
    case Hybrid = 'hybrid';
    case Onsite = 'onsite';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Worldwide => __('Remote, worldwide'),
            self::MultiCountry => __('Remote, multiple countries'),
            self::Mena => __('Remote, MENA'),
            self::Emea => __('Remote, EMEA'),
            self::Europe => __('Remote, Europe only'),
            self::UnitedStates => __('Remote, US only'),
            self::SpecificCountries => __('Remote, specific countries'),
            self::TimezoneBound => __('Remote, time zone bound'),
            self::Hybrid => __('Hybrid'),
            self::Onsite => __('On-site'),
            self::Unknown => __('Remote scope not stated'),
        };
    }
}
