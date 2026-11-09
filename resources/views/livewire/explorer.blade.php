<div>
    <header class="page-head split">
        <h1>{{ __('Jobs') }}</h1>
        <a class="btn" href="{{ route('jobs.add') }}" wire:navigate>{{ __('Add a job') }}</a>
    </header>

    <nav class="tabs" aria-label="{{ __('Job lists') }}">
        @foreach ($views as $key => $label)
            <button type="button" @class(['current' => $view === $key]) wire:click="$set('view', '{{ $key }}')" @if ($view === $key) aria-current="true" @endif>{{ __($label) }}</button>
        @endforeach
    </nav>

    <form class="filters" wire:submit.prevent>
        <div class="filter-row">
            <div class="field wide"><label for="f-search">{{ __('Search') }}</label><input id="f-search" type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Title or description') }}"></div>
            <div class="field"><label for="f-track">{{ __('Category') }}</label>
                <select id="f-track" wire:model.live="track"><option value="">{{ __('All') }}</option><option value="web">{{ __('Web development') }}</option>@if ($hasSecurityJobs)<option value="security">{{ __('Cybersecurity / SOC') }}</option>@endif</select></div>
            <div class="field"><label for="f-scope">{{ __('Remote eligibility') }}</label>
                <select id="f-scope" wire:model.live="scope"><option value="">{{ __('All') }}</option>@foreach ($scopes as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach</select></div>
            <div class="field"><label for="f-posted">{{ __('Published') }}</label>
                <select id="f-posted" wire:model.live="postedWithin"><option value="">{{ __('Any time') }}</option><option value="1">{{ __('Last 24 hours') }}</option><option value="3">{{ __('Last 72 hours') }}</option><option value="7">{{ __('Last 7 days') }}</option><option value="30">{{ __('Last 30 days') }}</option></select></div>
            <div class="field"><label for="f-sort">{{ __('Sort by') }}</label>
                <select id="f-sort" wire:model.live="sort"><option value="score">{{ __('Best match') }}</option><option value="newest">{{ __('Newest') }}</option></select></div>
        </div>
        <details class="more-filters" wire:ignore.self>
            <summary>{{ __('More filters') }}</summary>
            <div class="filter-row">
                <div class="field"><label for="f-emp">{{ __('Employment') }}</label>
                    <select id="f-emp" wire:model.live="employment"><option value="">{{ __('All') }}</option>@foreach ($employmentTypes as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach</select></div>
                <div class="field"><label for="f-country">{{ __('Country') }}</label><input id="f-country" type="text" wire:model.live.debounce.400ms="country"></div>
                <div class="field"><label for="f-company">{{ __('Company') }}</label><input id="f-company" type="text" wire:model.live.debounce.400ms="company"></div>
                <div class="field"><label for="f-source">{{ __('Source') }}</label>
                    <select id="f-source" wire:model.live="source"><option value="">{{ __('All') }}</option>@foreach ($sources as $item)<option value="{{ $item->id }}">{{ $item->label }}</option>@endforeach</select></div>
                <div class="field"><label for="f-score">{{ __('Match at least') }}</label><input id="f-score" type="number" min="0" max="100" inputmode="numeric" wire:model.live.debounce.400ms="minScore"></div>
                <div class="field"><label for="f-min">{{ __('Min $/month') }}</label><input id="f-min" type="number" min="0" inputmode="numeric" wire:model.live.debounce.400ms="minSalary"></div>
                <div class="field"><label for="f-max">{{ __('Max $/month') }}</label><input id="f-max" type="number" min="0" inputmode="numeric" wire:model.live.debounce.400ms="maxSalary"></div>
                <button type="button" class="btn quiet" wire:click="clearFilters">{{ __('Clear filters') }}</button>
            </div>
        </details>
    </form>

    <p class="result-count" wire:loading.class="loading" aria-live="polite">{{ trans_choice(':count job|:count jobs', $jobs->total(), ['count' => number_format($jobs->total())]) }}</p>

    @if ($jobs->isEmpty())
        <p class="empty">{{ __('No jobs match these filters. Clear a filter or try another list.') }}</p>
    @else
        <div wire:loading.class="loading"><x-job-rows :jobs="$jobs" :actions="true" /></div>
        {{ $jobs->links('components.pager') }}
    @endif
</div>
