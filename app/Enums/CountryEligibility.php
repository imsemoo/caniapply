<?php

namespace App\Enums;

enum CountryEligibility: string
{
    case Eligible = 'eligible';
    case Likely = 'likely';
    case Excluded = 'excluded';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Eligible => __('Open to your country'),
            self::Likely => __('Likely open to your country'),
            self::Excluded => __('Excludes your country'),
            self::Unknown => __('Not confirmed for your country'),
        };
    }
}
