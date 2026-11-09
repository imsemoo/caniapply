<?php

namespace App\Enums;

enum Lifecycle: string
{
    case New = 'new';
    case Seen = 'seen';
    case Updated = 'updated';
    case Expired = 'expired';
    case Closed = 'closed';
    case Reposted = 'reposted';

    public function label(): string
    {
        return match ($this) {
            self::New => __('New'),
            self::Seen => __('Previously discovered'),
            self::Updated => __('Updated'),
            self::Expired => __('Expired'),
            self::Closed => __('Closed'),
            self::Reposted => __('Reposted'),
        };
    }

    public function isGone(): bool
    {
        return $this === self::Expired || $this === self::Closed;
    }
}
