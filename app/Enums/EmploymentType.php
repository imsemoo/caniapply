<?php

namespace App\Enums;

enum EmploymentType: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contract = 'contract';
    case Freelance = 'freelance';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::FullTime => __('Full-time'),
            self::PartTime => __('Part-time'),
            self::Contract => __('Contract'),
            self::Freelance => __('Freelance project'),
            self::Unknown => __('Not stated'),
        };
    }

    public static function fromText(?string $text): self
    {
        $text = strtolower((string) $text);

        return match (true) {
            $text === '' => self::Unknown,
            (bool) preg_match('/part[\s_-]?time|دوام جزئي/u', $text) => self::PartTime,
            (bool) preg_match('/\bfreelance(r)?\b|\bgig\b|عمل حر/u', $text) => self::Freelance,
            (bool) preg_match('/(?<!smart )\bcontract(or)?\b|temporary|fixed[\s_-]?term|بعقد|عقد مؤقت/u', $text) => self::Contract,
            (bool) preg_match('/full[\s_-]?time|permanent|fulltime|دوام كامل/u', $text) => self::FullTime,
            default => self::Unknown,
        };
    }
}
