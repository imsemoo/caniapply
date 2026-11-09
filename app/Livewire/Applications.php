<?php

namespace App\Livewire;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Applications')]
class Applications extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $status = '';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function changeStatus(int $applicationId, string $status): void
    {
        validator(compact('status'), ['status' => ['required', Rule::enum(ApplicationStatus::class)]])->validate();

        $application = Application::query()->findOrFail($applicationId);
        $application->status = ApplicationStatus::from($status);

        if ($application->status === ApplicationStatus::Applied && $application->applied_on === null) {
            $application->applied_on = today();
        }

        $application->save();
    }

    public function render()
    {
        return view('livewire.applications', [
            'applications' => Application::query()->with('opportunity')
                ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
                ->latest('updated_at')->paginate(30),
            'counts' => Application::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'statuses' => ApplicationStatus::cases(),
        ]);
    }
}
