@props(['jobs', 'actions' => false])

<table class="jobs">
    <thead>
        <tr>
            <th scope="col" class="num">{{ __('Match') }}</th>
            <th scope="col">{{ __('Role') }}</th>
            <th scope="col">{{ __('Pay') }}</th>
            <th scope="col">{{ __('Where') }}</th>
            <th scope="col">{{ __('Posted') }}</th>
            @if ($actions)<th scope="col"><span class="sr">{{ __('Actions') }}</span></th>@endif
        </tr>
    </thead>
    <tbody>
        @foreach ($jobs as $job)
            <tr wire:key="job-{{ $job->id }}">
                <td class="num score-cell"><span @class(['score', 'high' => $job->score >= 80, 'muted' => $job->score< 70])>{{ $job->score ?? '–' }}</span></td>
                <td class="role">
                    <a href="{{ route('jobs.show', $job->id) }}" wire:navigate dir="auto">{{ $job->title }}</a>
                    <span class="sub" dir="auto">{{ $job->company ?: ($job->isFreelance() ? __('Freelance project') : __('Employer not named')) }}@if ($job->track === 'security') · {{ __('Security track') }} @endif</span>
                    @if (($job->similar_count ?? 1) > 1)<span class="sub">{{ __('Posted :count times, for different locations', ['count' => $job->similar_count]) }}</span>@endif
                    @if ($actions && $job->safeUrl())<a class="open-listing" href="{{ $job->safeUrl() }}" target="_blank" rel="noopener noreferrer nofollow">{{ __('Open original listing') }}</a>@endif
                </td>
                <td class="pay">{{ $job->compensationLabel() }}</td>
                <td class="where">
                    {{ $job->remote_scope->label() }}
                    <span class="sub elig-{{ $job->country_eligibility->value }}">{{ $job->country_eligibility->label() }}</span>
                </td>
                <td class="posted">{{ $job->posted_at?->diffForHumans(short: app()->getLocale() !== 'ar') ?? __('No date') }}</td>
                @if ($actions)
                    <td class="row-actions">
                        @if ($job->application)
                            <span class="tag">{{ $job->application->status->label() }}</span>
                        @else
                            <div class="action-grid">
                                <button type="button" class="btn small" wire:click="mark({{ $job->id }}, 'saved')">{{ __('Save') }}</button>
                                <button type="button" class="btn small" wire:click="mark({{ $job->id }}, 'interested')">{{ __('Interested') }}</button>
                                <button type="button" class="btn small" wire:click="mark({{ $job->id }}, 'applied')">{{ __('Applied') }}</button>
                                <button type="button" class="btn small quiet" wire:click="mark({{ $job->id }}, 'rejected')">{{ __('Reject') }}</button>
                            </div>
                        @endif
                    </td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>
