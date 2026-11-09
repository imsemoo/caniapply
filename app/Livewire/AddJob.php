<?php

namespace App\Livewire;

use App\Models\CandidateProfile;
use App\Models\JobSource;
use App\Models\OpportunityListing;
use App\Services\Discovery\ListingIngestor;
use App\Services\Evaluation\OpportunityEvaluator;
use App\Sources\RawListing;
use App\Sources\SourceRegistry;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The permitted route for jobs on sites that forbid automated reading. */
#[Title('Add a job')]
class AddJob extends Component
{
    public string $title = '';

    public string $company = '';

    public string $url = '';

    public string $location = '';

    public string $employment = '';

    public string $track = 'web';

    public bool $remote = true;

    public bool $freelance = false;

    public string $description = '';

    public function save(ListingIngestor $ingestor, OpportunityEvaluator $evaluator, SourceRegistry $registry)
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:250'],
            'company' => ['nullable', 'string', 'max:250'],
            'url' => ['required', 'url:http,https', 'max:2000'],
            'location' => ['nullable', 'string', 'max:250'],
            'employment' => ['nullable', 'in:full_time,part_time,contract'],
            'track' => ['required', 'in:web,security'],
            'description' => ['required', 'string', 'min:40', 'max:40000'],
        ]);

        $registry->sync();
        $source = JobSource::query()->where('key', 'manual')->firstOrFail();

        $ingestor->ingest($source, new RawListing(
            externalId: hash('sha256', $validated['url']),
            url: $validated['url'],
            title: $validated['title'],
            company: $validated['company'] ?: null,
            description: $validated['description'],
            location: $validated['location'] ?: null,
            postedAt: now(),
            employmentType: $validated['employment'] ?: null,
            remote: $this->remote ? true : null,
            isFreelance: $this->freelance,
        ), CandidateProfile::current(), $validated['track']);

        $opportunity = OpportunityListing::query()
            ->where('url_key', OpportunityListing::urlKey($validated['url']))
            ->firstOrFail()
            ->opportunity;

        if ($opportunity->needs_evaluation) {
            $evaluator->evaluate($opportunity);
        }

        return $this->redirectRoute('jobs.show', ['opportunity' => $opportunity->id]);
    }

    public function render()
    {
        return view('livewire.add-job');
    }
}
