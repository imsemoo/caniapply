<?php

namespace App\Livewire;

use App\Jobs\DiscoverFromSource;
use App\Models\JobSource;
use App\Sources\SourceRegistry;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Sources')]
class Sources extends Component
{
    public function mount(SourceRegistry $registry): void
    {
        $registry->sync();
    }

    public function toggle(int $id): void
    {
        $source = JobSource::query()->where('type', '!=', 'manual')->findOrFail($id);
        $source->update(['enabled' => ! $source->enabled]);
    }

    public function runNow(int $id): void
    {
        DiscoverFromSource::dispatch(JobSource::query()->where('enabled', true)->where('type', '!=', 'manual')->findOrFail($id)->id);
        session()->flash('status', __('Queued. Results appear after the worker finishes.'));
    }

    public function render()
    {
        return view('livewire.sources', [
            'sources' => JobSource::query()->where('type', '!=', 'manual')->withCount('listings')->orderBy('label')->get(),
            'unavailable' => config('hunter.unavailable_sources'),
            'telegramReady' => filled(config('hunter.telegram.token')) && filled(config('hunter.telegram.chat_id')),
            'llmProvider' => config('hunter.llm.provider'),
            'llmReady' => filled(config('hunter.llm.api_key')) && config('hunter.llm.provider') !== 'none',
        ]);
    }
}
