<div>
    <header class="page-head">
        <h1>{{ __('Overview') }}</h1>
        <p class="sub">{{ $lastRun ? __('Last successful fetch :time', ['time' => \Illuminate\Support\Carbon::parse($lastRun)->diffForHumans()]) : __('No fetch has finished yet.') }}</p>
    </header>

    <dl class="ledger">
        <div><dt>{{ __('Discovered') }}</dt><dd>{{ number_format($stats['total']) }}</dd></div>
        <div><dt>{{ __('New today') }}</dt><dd>{{ number_format($stats['new_today']) }}</dd></div>
        <div><dt>{{ __('High value') }}</dt><dd>{{ number_format($stats['high_value']) }}</dd></div>
        <div><dt>{{ __('Pay $:amount+', ['amount' => number_format($floor)]) }}</dt><dd>{{ number_format($stats['meets_floor']) }}</dd></div>
        <div><dt>{{ __('Salary undisclosed') }}</dt><dd>{{ number_format($stats['undisclosed']) }}</dd></div>
        <div><dt>{{ __('Freelance') }}</dt><dd>{{ number_format($stats['freelance']) }}</dd></div>
        <div><dt>{{ __('Applied') }}</dt><dd>{{ number_format($stats['applied']) }}</dd></div>
        <div><dt>{{ __('Interviews') }}</dt><dd>{{ number_format($stats['interviews']) }}</dd></div>
        <div><dt>{{ __('Offers') }}</dt><dd>{{ number_format($stats['offers']) }}</dd></div>
    </dl>

    @if ($troubled->isNotEmpty())
        <p class="notice">
            {{ __('Needs attention: :sources.', ['sources' => $troubled->pluck('label')->join('، ')]) }}
            <a href="{{ route('sources') }}" wire:navigate>{{ __('See sources') }}</a>
        </p>
    @endif

    @if ($followUps->isNotEmpty())
        <section class="block">
            <h2>{{ __('Follow up') }}</h2>
            <ul class="plain-list">
                @foreach ($followUps as $application)
                    <li wire:key="follow-{{ $application->id }}">
                        <a href="{{ route('jobs.show', $application->opportunity_id) }}" wire:navigate dir="auto">{{ $application->opportunity->title }}</a>
                        <span class="sub">{{ $application->follow_up_on->isPast() ? __('Due') : __('On') }} {{ $application->follow_up_on->translatedFormat('j M') }} · {{ $application->status->label() }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="block">
        <div class="block-head">
            <h2>{{ __('Decide on these') }}</h2>
            <a href="{{ route('jobs') }}" wire:navigate>{{ __('All jobs') }}</a>
        </div>
        @if ($next->isEmpty())
            <p class="empty">{{ __('Nothing from the last seven days is waiting on you. New matches land here after each fetch.') }}</p>
        @else
            <x-job-rows :jobs="$next" />
        @endif
    </section>
</div>
