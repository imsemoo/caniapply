<?php

namespace App\Livewire;

use App\Enums\ApplicationStatus;
use App\Enums\Bucket;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\JobSource;
use App\Models\Opportunity;
use App\Services\Reports\MarketAnalytics;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Overview')]
class Overview extends Component
{
    public function render(MarketAnalytics $analytics)
    {
        $profile = CandidateProfile::current();

        return view('livewire.overview', [
            'stats' => $analytics->overview($profile->compensation['full_time_floor'], $profile->thresholds['strong']),
            'floor' => $profile->compensation['full_time_floor'],
            'next' => Opportunity::query()->active()
                ->whereIn('bucket', [Bucket::Qualified->value, Bucket::Undisclosed->value])
                ->where('score', '>=', $profile->thresholds['review'])
                ->where('first_seen_at', '>=', now()->subDays(7))
                ->whereDoesntHave('application')
                ->orderByDesc('score')
                ->limit(40)->get()
                ->sortByDesc(fn (Opportunity $job) => [$job->freshnessAt()->gte(now()->subHours(72)) ? 1 : 0, $job->score])
                ->unique(fn (Opportunity $job) => filled($job->company_norm) ? $job->title_norm.'|'.$job->company_norm : $job->id)
                ->take(10)->values(),
            'followUps' => Application::query()->with('opportunity')
                ->whereNotNull('follow_up_on')->where('follow_up_on', '<=', today()->addDays(3))
                ->whereNot('status', ApplicationStatus::Rejected->value)
                ->orderBy('follow_up_on')->limit(8)->get(),
            'troubled' => JobSource::query()->where('enabled', true)->whereIn('health', ['failing', 'degraded'])->get(),
            'lastRun' => JobSource::query()->max('last_success_at'),
        ]);
    }
}
