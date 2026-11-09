<?php

namespace App\Livewire;

use App\Enums\ApplicationStatus;
use App\Models\Opportunity;
use App\Services\Applications\ApplicationLetterWriter;
use App\Services\Applications\ApplicationTracker;
use App\Services\Evaluation\ApplicationStrategist;
use App\Services\Llm\LlmFailed;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class OpportunityDetail extends Component
{
    #[Locked]
    public int $opportunityId;

    public string $status = 'saved';

    public ?string $applied_on = null;

    public ?string $contact_name = null;

    public ?string $contact_details = null;

    public ?string $interview_notes = null;

    public ?string $follow_up_on = null;

    public ?string $salary_offered = null;

    public ?string $notes = null;

    public bool $saved = false;

    public ?string $cover_letter = null;

    public ?string $letterError = null;

    public bool $letterSaved = false;

    public function mount(Opportunity $opportunity): void
    {
        $this->opportunityId = $opportunity->id;
        $this->fillFromApplication($opportunity);
    }

    private function opportunity(): Opportunity
    {
        return Opportunity::query()->with(['listings.source', 'changes', 'application'])->findOrFail($this->opportunityId);
    }

    private function fillFromApplication(Opportunity $opportunity): void
    {
        $application = $opportunity->application;
        if ($application === null) {
            return;
        }

        $this->status = $application->status->value;
        $this->applied_on = $application->applied_on?->toDateString();
        $this->follow_up_on = $application->follow_up_on?->toDateString();
        $this->contact_name = $application->contact_name;
        $this->contact_details = $application->contact_details;
        $this->interview_notes = $application->interview_notes;
        $this->salary_offered = $application->salary_offered;
        $this->notes = $application->notes;
        $this->cover_letter = $application->cover_letter;
    }

    public function mark(string $status, ApplicationTracker $tracker): void
    {
        $opportunity = $this->opportunity();
        $tracker->setStatus($opportunity, ApplicationStatus::from($status));
        $this->fillFromApplication($opportunity->refresh());
    }

    public function saveApplication(): void
    {
        $this->saved = false;

        $validated = $this->validate([
            'status' => ['required', Rule::enum(ApplicationStatus::class)],
            'applied_on' => ['nullable', 'date'],
            'contact_name' => ['nullable', 'string', 'max:200'],
            'contact_details' => ['nullable', 'string', 'max:250'],
            'interview_notes' => ['nullable', 'string', 'max:10000'],
            'follow_up_on' => ['nullable', 'date'],
            'salary_offered' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $this->opportunity()->application()->updateOrCreate(
            ['opportunity_id' => $this->opportunityId],
            array_map(fn ($value) => $value === '' ? null : $value, $validated),
        );

        $this->saved = true;
    }

    public function generateStrategy(ApplicationStrategist $strategist): void
    {
        $opportunity = $this->opportunity();
        $strategist->generate($opportunity);
        $this->fillFromApplication($opportunity->refresh());
    }

    public function writeLetter(ApplicationLetterWriter $writer): void
    {
        $this->letterError = null;
        $this->letterSaved = false;

        try {
            $this->cover_letter = $writer->write($this->opportunity())->cover_letter;
        } catch (LlmFailed $e) {
            $this->letterError = __('The draft could not be written: :reason', ['reason' => $e->getMessage()]);
        }
    }

    public function saveLetter(): void
    {
        $this->validate(['cover_letter' => ['nullable', 'string', 'max:8000']]);

        $this->opportunity()->application()->updateOrCreate(['opportunity_id' => $this->opportunityId], ['cover_letter' => $this->cover_letter]);
        $this->letterSaved = true;
    }

    public function render(ApplicationLetterWriter $writer)
    {
        $opportunity = $this->opportunity();

        return view('livewire.opportunity-detail', [
            'job' => $opportunity,
            'analysis' => $opportunity->analysis ?? [],
            'statuses' => ApplicationStatus::cases(),
            'canWriteLetter' => $writer->canWrite(),
        ])->title($opportunity->title);
    }
}
