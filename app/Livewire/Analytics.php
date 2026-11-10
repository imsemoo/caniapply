<?php

namespace App\Livewire;

use App\Services\Reports\MarketAnalytics;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Analytics')]
class Analytics extends Component
{
    public function render(MarketAnalytics $analytics)
    {
        return view('livewire.analytics', [
            'sources' => $analytics->sourceProductivity(),
            'salaries' => $analytics->salaryDistribution(),
            'regions' => $analytics->remoteByRegion(),
            // Reads the analysis of every job, so it is worked out once every ten minutes.
            'skills' => Cache::remember('analytics.top-skills', 600, fn () => $analytics->topSkills()),
            'trend' => $analytics->discoveryTrend(),
            'conversion' => $analytics->conversion(),
            'rejections' => $analytics->rejectionReasons(),
        ]);
    }
}
