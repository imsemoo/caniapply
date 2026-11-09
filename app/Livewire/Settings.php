<?php

namespace App\Livewire;

use App\Models\CandidateProfile;
use App\Services\Discovery\EligibilityRefresher;
use App\Support\HomeCountry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Settings')]
class Settings extends Component
{
    public string $full_name = '';

    public string $headline = '';

    public string $country = '';

    public int $years_experience = 0;

    public array $compensation = [];

    public array $weights = [];

    public array $thresholds = [];

    public string $webPrimary = '';

    public string $webSecondary = '';

    public string $webLight = '';

    public string $webQueries = '';

    public string $projectQueries = '';

    public bool $saved = false;

    public bool $countryRechecked = false;

    public function mount(): void
    {
        $profile = CandidateProfile::current();
        $web = $profile->track('web');

        $this->full_name = $profile->full_name;
        $this->headline = $profile->headline;
        $this->country = $profile->country;
        $this->years_experience = $profile->years_experience;
        $this->compensation = $profile->compensation;
        $this->weights = $profile->scoring_weights;
        $this->thresholds = $profile->thresholds;
        $this->webPrimary = implode(', ', $web['skills']['primary']);
        $this->webSecondary = implode(', ', $web['skills']['secondary']);
        $this->webLight = implode(', ', $web['skills']['light']);
        $this->webQueries = implode("\n", $web['queries']);
        $this->projectQueries = implode("\n", $web['project_queries']);
    }

    protected function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:120'],
            'headline' => ['required', 'string', 'max:120'],
            'country' => ['required', Rule::in(HomeCountry::names())],
            'years_experience' => ['required', 'integer', 'min:0', 'max:50'],
            'compensation.*' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'weights.*' => ['required', 'integer', 'min:0', 'max:100'],
            'thresholds.*' => ['required', 'integer', 'min:0', 'max:100'],
            'webPrimary' => ['nullable', 'string', 'max:2000'],
            'webSecondary' => ['nullable', 'string', 'max:2000'],
            'webLight' => ['nullable', 'string', 'max:2000'],
            'webQueries' => ['required', 'string', 'max:4000'],
            'projectQueries' => ['required', 'string', 'max:4000'],
        ];
    }

    public function save(EligibilityRefresher $refresher): void
    {
        $this->saved = false;
        $this->countryRechecked = false;
        $this->validate();

        if (array_sum($this->weights) !== 100) {
            $this->addError('weights', __('The six weights must add up to 100. They now add up to :total.', ['total' => array_sum($this->weights)]));

            return;
        }

        $profile = CandidateProfile::current();
        $tracks = $profile->tracks;

        $tracks['web']['experience_years'] = $this->years_experience;
        $tracks['web']['skills'] = [
            'primary' => $this->list($this->webPrimary, ','),
            'secondary' => $this->list($this->webSecondary, ','),
            'light' => $this->list($this->webLight, ','),
        ];
        $tracks['web']['queries'] = $this->list($this->webQueries, "\n");
        $tracks['web']['project_queries'] = $this->list($this->projectQueries, "\n");

        $countryChanged = $profile->country !== $this->country;

        $profile->update([
            'full_name' => $this->full_name,
            'headline' => $this->headline,
            'country' => $this->country,
            'years_experience' => $this->years_experience,
            'tracks' => $tracks,
            'compensation' => array_map('floatval', $this->compensation),
            'scoring_weights' => array_map('intval', $this->weights),
            'thresholds' => array_map('intval', $this->thresholds),
        ]);

        if ($countryChanged) {
            $refresher->run();
            $this->countryRechecked = true;
            Artisan::queue('hunter:reevaluate', ['--all' => true]);
        }

        $this->saved = true;
    }

    public function rescoreAll(): void
    {
        Artisan::queue('hunter:reevaluate', ['--all' => true]);
        session()->flash('status', __('Re-scoring queued. With an AI provider set, this uses your daily quota.'));
    }

    private function list(string $text, string $separator): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode($separator, str_replace("\r", '', $text))))));
    }

    public function labelFor(string $key): string
    {
        return Str::headline($key);
    }

    public function render()
    {
        return view('livewire.settings', ['countries' => HomeCountry::names()]);
    }
}
