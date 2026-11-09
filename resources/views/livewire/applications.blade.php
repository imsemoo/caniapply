<div>
    <header class="page-head"><h1>{{ __('Applications') }}</h1></header>

    <nav class="tabs" aria-label="{{ __('Application status') }}">
        <button type="button" @class(['current' => $status === '']) wire:click="$set('status', '')">{{ __('All') }}</button>
        @foreach ($statuses as $option)
            <button type="button" @class(['current' => $status === $option->value]) wire:click="$set('status', '{{ $option->value }}')">{{ $option->label() }} <span class="count">{{ $counts[$option->value] ?? 0 }}</span></button>
        @endforeach
    </nav>

    @if ($applications->isEmpty())
        <p class="empty">{{ __('Nothing here yet. Press Save, Interested or Applied on a job and it appears in this list.') }}</p>
    @else
        <table class="jobs apps">
            <thead><tr><th scope="col">{{ __('Role') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Applied') }}</th><th scope="col">{{ __('Follow up') }}</th><th scope="col">{{ __('Offer') }}</th></tr></thead>
            <tbody>
            @foreach ($applications as $application)
                <tr wire:key="app-{{ $application->id }}">
                    <td class="role">
                        <a href="{{ route('jobs.show', $application->opportunity_id) }}" wire:navigate dir="auto">{{ $application->opportunity->title }}</a>
                        <span class="sub" dir="auto">{{ $application->opportunity->company ?: __('Freelance project') }}</span>
                    </td>
                    <td>
                        <label class="sr" for="status-{{ $application->id }}">{{ __('Status') }}</label>
                        <select id="status-{{ $application->id }}" wire:change="changeStatus({{ $application->id }}, $event.target.value)">
                            @foreach ($statuses as $option)
                                <option value="{{ $option->value }}" @selected($application->status === $option)>{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>{{ $application->applied_on?->translatedFormat('j M Y') ?? __('Not yet') }}</td>
                    <td>{{ $application->follow_up_on?->translatedFormat('j M') ?? __('None') }}</td>
                    <td dir="auto">{{ $application->salary_offered ?? __('None') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        {{ $applications->links('components.pager') }}
    @endif
</div>
