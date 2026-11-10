<?php

namespace App\Livewire;

use App\Enums\ApplicationStatus;
use App\Enums\Bucket;
use App\Enums\EmploymentType;
use App\Enums\RemoteScope;
use App\Models\JobSource;
use App\Models\Opportunity;
use App\Services\Applications\ApplicationTracker;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Jobs')]
class Explorer extends Component
{
    use WithPagination;

    #[Url(except: 'open')]
    public string $view = 'open';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $track = '';

    #[Url(except: '')]
    public string $employment = '';

    #[Url(except: '')]
    public string $scope = '';

    #[Url(except: '')]
    public string $country = '';

    #[Url(except: '')]
    public string $company = '';

    #[Url(except: '')]
    public string $source = '';

    #[Url(except: '')]
    public string $minScore = '';

    #[Url(except: '')]
    public string $minSalary = '';

    #[Url(except: '')]
    public string $maxSalary = '';

    #[Url(except: '')]
    public string $postedWithin = '';

    #[Url(except: 'score')]
    public string $sort = 'score';

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'track', 'employment', 'scope', 'country', 'company', 'source', 'minScore', 'minSalary', 'maxSalary', 'postedWithin');
        $this->resetPage();
    }

    public function mark(int $id, string $status, ApplicationTracker $tracker): void
    {
        $tracker->setStatus(Opportunity::query()->findOrFail($id), ApplicationStatus::from($status));
    }

    private function query(): Builder
    {
        $query = Opportunity::query();

        match ($this->view) {
            'undisclosed' => $query->active()->where('bucket', Bucket::Undisclosed->value),
            'freelance' => $query->active()->where('employment_type', EmploymentType::Freelance->value)->where('bucket', '!=', Bucket::Rejected->value),
            'flagged' => $query->active()->where('bucket', Bucket::Flagged->value),
            'rejected' => $query->where('bucket', Bucket::Rejected->value),
            'gone' => $query->whereIn('lifecycle', ['expired', 'closed']),
            default => $query->active()->whereIn('bucket', [Bucket::Qualified->value, Bucket::Undisclosed->value])->where('employment_type', '!=', EmploymentType::Freelance->value),
        };

        if ($this->view !== 'rejected') {
            $query->whereDoesntHave('application', fn ($q) => $q->where('status', ApplicationStatus::Rejected->value));
        }

        return $query
            ->when($this->search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('title', 'like', "%{$this->search}%")->orWhere('description', 'like', "%{$this->search}%")))
            ->when($this->track !== '', fn ($q) => $q->where('track', $this->track))
            ->when($this->employment !== '', fn ($q) => $q->where('employment_type', $this->employment))
            ->when($this->scope !== '', fn ($q) => $q->where('remote_scope', $this->scope))
            ->when($this->country !== '', fn ($q) => $q->where('location_text', 'like', "%{$this->country}%"))
            ->when($this->company !== '', fn ($q) => $q->where('company', 'like', "%{$this->company}%"))
            ->when($this->source !== '', fn ($q) => $q->whereHas('listings', fn ($l) => $l->where('job_source_id', (int) $this->source)))
            ->when(is_numeric($this->minScore), fn ($q) => $q->where('score', '>=', (int) $this->minScore))
            ->when(is_numeric($this->minSalary), fn ($q) => $q->where('monthly_usd_max', '>=', (float) $this->minSalary))
            ->when(is_numeric($this->maxSalary), fn ($q) => $q->where('monthly_usd_min', '<=', (float) $this->maxSalary))
            ->when(is_numeric($this->postedWithin), fn ($q) => $q->where(fn ($inner) => $inner->where('posted_at', '>=', now()->subDays((int) $this->postedWithin))->orWhere(fn ($undated) => $undated->whereNull('posted_at')->where('first_seen_at', '>=', now()->subDays((int) $this->postedWithin)))));
    }

    /** The same role posted per region shows once, as its best-scoring posting. */
    private function withoutRepeatedPostings(Builder $query): Builder
    {
        $rejected = ApplicationStatus::Rejected->value;
        // A posting you turned down must not stand in for its siblings, and one you are tracking must stay in sight.
        $turnedDown = $this->view === 'rejected' ? '' : " and not exists (select 1 from applications a where a.opportunity_id = o2.id and a.status = '{$rejected}')";
        $tracked = "exists (select 1 from applications a where a.opportunity_id = o2.id and a.status != '{$rejected}')";
        $sameRole = "o2.title_norm = opportunities.title_norm and o2.company_norm = opportunities.company_norm and o2.bucket = opportunities.bucket and (o2.lifecycle in ('expired', 'closed')) = (opportunities.lifecycle in ('expired', 'closed')){$turnedDown}";

        return $query
            ->select('opportunities.*')
            ->selectRaw("(select count(*) from opportunities o2 where {$sameRole}) as similar_count")
            ->whereRaw("(coalesce(opportunities.company_norm, '') = '' or opportunities.id = (select o2.id from opportunities o2 where {$sameRole} order by {$tracked} desc, o2.score desc, o2.id limit 1))");
    }

    private function hasFreelanceJobs(): bool
    {
        return $this->view === 'freelance' || Opportunity::query()->where('employment_type', EmploymentType::Freelance->value)->exists();
    }

    public function render()
    {
        // With a filter on, the matching posting may not be the best-scoring one, so all of them stay.
        $filtered = implode('', [$this->search, $this->track, $this->employment, $this->scope, $this->country, $this->company, $this->source, $this->minScore, $this->minSalary, $this->maxSalary, $this->postedWithin]) !== '';
        $query = ($filtered ? $this->query() : $this->withoutRepeatedPostings($this->query()))->with(['application', 'listings.source']);

        $this->sort === 'newest'
            ? $query->orderByDesc('posted_at')->orderByDesc('id')
            : $query->orderByDesc('score')->orderByDesc('posted_at');

        return view('livewire.explorer', [
            'jobs' => $query->paginate(30),
            'sources' => JobSource::query()->orderBy('label')->get(['id', 'label']),
            'employmentTypes' => EmploymentType::cases(),
            'scopes' => RemoteScope::cases(),
            'hasSecurityJobs' => Opportunity::query()->where('track', 'security')->exists(),
            'views' => ['open' => 'Worth a look', 'undisclosed' => 'Salary undisclosed', ...($this->hasFreelanceJobs() ? ['freelance' => 'Freelance'] : []), 'flagged' => 'Flagged', 'rejected' => 'Rejected', 'gone' => 'Expired or closed'],
        ]);
    }
}
