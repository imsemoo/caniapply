<?php

namespace App\Services\Reports;

use App\Enums\ApplicationStatus;
use App\Enums\Bucket;
use App\Models\Application;
use App\Models\JobSource;
use App\Models\Opportunity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MarketAnalytics
{
    public function overview(int $floor, int $highValueScore): array
    {
        $submitted = array_map(fn (ApplicationStatus $s) => $s->value, ApplicationStatus::submitted());
        $interviewing = array_map(fn (ApplicationStatus $s) => $s->value, ApplicationStatus::interviewing());

        return [
            'total' => Opportunity::query()->count(),
            'new_today' => Opportunity::query()->where('first_seen_at', '>=', today())->count(),
            'high_value' => Opportunity::query()->active()->where('score', '>=', $highValueScore)->whereIn('bucket', [Bucket::Qualified->value, Bucket::Undisclosed->value])->count(),
            'meets_floor' => Opportunity::query()->active()->where('monthly_usd_max', '>=', $floor)->where('bucket', Bucket::Qualified->value)->count(),
            'undisclosed' => Opportunity::query()->active()->where('bucket', Bucket::Undisclosed->value)->count(),
            'freelance' => Opportunity::query()->active()->where('employment_type', 'freelance')->whereIn('bucket', [Bucket::Qualified->value, Bucket::Flagged->value])->count(),
            'applied' => Application::query()->whereIn('status', $submitted)->count(),
            'interviews' => Application::query()->whereIn('status', $interviewing)->count(),
            'offers' => Application::query()->where('status', ApplicationStatus::Offer->value)->count(),
        ];
    }

    public function sourceProductivity(?Carbon $since = null): array
    {
        return DB::table('opportunity_listings as l')
            ->join('job_sources as s', 's.id', '=', 'l.job_source_id')
            ->join('opportunities as o', 'o.id', '=', 'l.opportunity_id')
            ->when($since, fn ($q) => $q->where('l.first_seen_at', '>=', $since))
            ->groupBy('s.label')
            ->selectRaw('s.label as label, count(*) as total, sum(case when o.bucket in (?, ?) then 1 else 0 end) as qualified', [Bucket::Qualified->value, Bucket::Undisclosed->value])
            ->orderByDesc('qualified')->orderByDesc('total')
            ->get()->map(fn ($r) => ['label' => $r->label, 'total' => (int) $r->total, 'qualified' => (int) $r->qualified])->all();
    }

    public function salaryDistribution(): array
    {
        $bands = ['Under $1,500' => [0, 1500], '$1,500–2,000' => [1500, 2000], '$2,000–3,000' => [2000, 3000], '$3,000–5,000' => [3000, 5000], '$5,000–8,000' => [5000, 8000], '$8,000+' => [8000, PHP_INT_MAX]];
        $values = Opportunity::query()->whereNotNull('monthly_usd_max')->pluck('monthly_usd_max');

        $distribution = [];
        foreach ($bands as $label => [$low, $high]) {
            $distribution[$label] = $values->filter(fn ($v) => $v >= $low && $v < $high)->count();
        }

        return $distribution;
    }

    public function remoteByRegion(): array
    {
        return Opportunity::query()->select('remote_scope', DB::raw('count(*) as total'))->groupBy('remote_scope')->orderByDesc('total')
            ->get()->mapWithKeys(fn (Opportunity $o) => [$o->remote_scope->label() => (int) $o->getAttribute('total')])->all();
    }

    public function topSkills(int $limit = 12, ?Carbon $since = null): array
    {
        $counts = [];

        Opportunity::query()
            ->whereNotNull('analysis')
            ->when($since, fn ($q) => $q->where('first_seen_at', '>=', $since))
            ->select('id', 'analysis')
            ->chunkById(500, function ($rows) use (&$counts) {
                foreach ($rows as $row) {
                    foreach ([...($row->analysis['skills_matched'] ?? []), ...($row->analysis['skills_partial'] ?? []), ...($row->analysis['skills_missing'] ?? [])] as $skill) {
                        $counts[$skill] = ($counts[$skill] ?? 0) + 1;
                    }
                }
            });

        arsort($counts);

        return array_slice($counts, 0, $limit, true);
    }

    /** Daily discoveries over the window, with empty days filled in. */
    public function discoveryTrend(int $days = 30): array
    {
        $rows = Opportunity::query()
            ->where('first_seen_at', '>=', today()->subDays($days - 1))
            ->selectRaw('date(first_seen_at) as day, count(*) as total')
            ->groupBy('day')->pluck('total', 'day');

        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = today()->subDays($i)->toDateString();
            $trend[$day] = (int) ($rows[$day] ?? 0);
        }

        return $trend;
    }

    public function conversion(): array
    {
        $counts = Application::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $count = fn (array $statuses) => (int) collect($statuses)->sum(fn (ApplicationStatus $s) => $counts[$s->value] ?? 0);

        $applied = $count(ApplicationStatus::submitted()) + (int) Application::query()->where('status', ApplicationStatus::Rejected->value)->whereNotNull('applied_on')->count();
        $interviews = $count(ApplicationStatus::interviewing()) + $count([ApplicationStatus::Offer]);
        $offers = $count([ApplicationStatus::Offer]);

        return [
            'applied' => $applied,
            'interviews' => $interviews,
            'offers' => $offers,
            'interview_rate' => $applied > 0 ? (int) round(100 * $interviews / $applied) : null,
            'offer_rate' => $applied > 0 ? (int) round(100 * $offers / $applied) : null,
        ];
    }

    public function rejectionReasons(int $limit = 10, ?Carbon $since = null): array
    {
        return Opportunity::query()->whereNotNull('reject_reason')
            ->when($since, fn ($q) => $q->where('first_seen_at', '>=', $since))
            ->selectRaw('reject_reason, count(*) as total')->groupBy('reject_reason')->orderByDesc('total')->limit($limit)
            ->pluck('total', 'reject_reason')->map(fn ($v) => (int) $v)->all();
    }

    public function weeklyFigures(Carbon $from, Carbon $to): array
    {
        $week = Opportunity::query()->whereBetween('first_seen_at', [$from, $to]);
        $discovered = (clone $week)->count();
        $disclosed = (clone $week)->where('salary_status', 'disclosed')->count();
        $values = (clone $week)->whereNotNull('monthly_usd_max')->pluck('monthly_usd_max')->sort()->values();

        $sources = collect($this->sourceProductivity($from))->filter(fn ($s) => $s['qualified'] > 0)->take(5)->pluck('qualified', 'label')->all();

        return [
            'period' => $from->toDateString().' to '.$to->toDateString(),
            'discovered' => $discovered,
            'qualified' => (clone $week)->whereIn('bucket', [Bucket::Qualified->value, Bucket::Undisclosed->value])->count(),
            'rejected' => (clone $week)->where('bucket', Bucket::Rejected->value)->count(),
            'disclosed_percent' => $discovered > 0 ? (int) round(100 * $disclosed / $discovered) : 0,
            'median_monthly_usd' => $values->isNotEmpty() ? (int) round($values->median()) : null,
            'top_skills' => $this->topSkills(8, $from),
            'top_sources' => $sources,
            'top_rejections' => $this->rejectionReasons(5, $from),
            'failing_sources' => JobSource::query()->whereIn('health', ['failing', 'degraded'])->where('enabled', true)->pluck('label')->all(),
        ];
    }
}
