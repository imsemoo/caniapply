<?php

namespace App\Livewire;

use App\Services\Reports\MarketAnalytics;
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
            'skills' => $analytics->topSkills(),
            'trend' => $analytics->discoveryTrend(),
            'conversion' => $analytics->conversion(),
            'rejections' => $analytics->rejectionReasons(),
        ]);
    }
}
