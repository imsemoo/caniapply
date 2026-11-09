<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketReport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'figures' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
            'sent_at' => 'datetime',
        ];
    }
}
