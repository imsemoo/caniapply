<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case Saved = 'saved';
    case Interested = 'interested';
    case Applied = 'applied';
    case HrInterview = 'hr_interview';
    case TechnicalInterview = 'technical_interview';
    case Assessment = 'assessment';
    case Offer = 'offer';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Saved => __('Saved'),
            self::Interested => __('Interested'),
            self::Applied => __('Applied'),
            self::HrInterview => __('HR interview'),
            self::TechnicalInterview => __('Technical interview'),
            self::Assessment => __('Assessment'),
            self::Offer => __('Offer'),
            self::Rejected => __('Rejected'),
        };
    }

    /** Statuses that mean an application actually went out. */
    public static function submitted(): array
    {
        return [self::Applied, self::HrInterview, self::TechnicalInterview, self::Assessment, self::Offer];
    }

    public static function interviewing(): array
    {
        return [self::HrInterview, self::TechnicalInterview, self::Assessment];
    }
}
