<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobSource extends Model
{
    protected $guarded = ['id'];

    public static function removeDemoData(): void
    {
        $demo = self::query()->where('key', 'demo')->first();

        if ($demo !== null) {
            Opportunity::query()->where('primary_source_id', $demo->id)->delete();
            $demo->delete();
        }
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
            'last_success_at' => 'datetime',
            'failure_alerted_at' => 'datetime',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SourceRun::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(OpportunityListing::class);
    }

    public function isDue(int $minIntervalHours): bool
    {
        // Ten minutes of slack so a 3-hour cron still fires a 3-hour source.
        return $this->last_run_at === null
            || $this->last_run_at->lte(now()->subHours($minIntervalHours)->addMinutes(10));
    }
}
