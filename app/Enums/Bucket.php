<?php

namespace App\Enums;

enum Bucket: string
{
    case Pending = 'pending';
    case Qualified = 'qualified';
    case Undisclosed = 'undisclosed';
    case Flagged = 'flagged';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Awaiting evaluation'),
            self::Qualified => __('Qualified'),
            self::Undisclosed => __('Salary undisclosed'),
            self::Flagged => __('Flagged for review'),
            self::Rejected => __('Rejected'),
        };
    }
}
