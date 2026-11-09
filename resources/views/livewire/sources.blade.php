<div>
    <header class="page-head"><h1>{{ __('Sources') }}</h1></header>

    @if (session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif

    <dl class="status-line">
        <div><dt>{{ __('Telegram') }}</dt><dd @class(['warn' => ! $telegramReady])>{{ $telegramReady ? __('Connected') : __('Not set up. Alerts are held until the token and chat id are in .env.') }}</dd></div>
        <div><dt>{{ __('AI scoring') }}</dt><dd @class(['warn' => ! $llmReady])>{{ $llmReady ? __('On (:provider)', ['provider' => $llmProvider]) : __('Off. Jobs are scored by the rule engine.') }}</dd></div>
    </dl>

    <section class="block">
        <table class="plain sources">
            <thead><tr><th scope="col">{{ __('Source') }}</th><th scope="col">{{ __('Health') }}</th><th scope="col">{{ __('Last success') }}</th><th scope="col" class="num">{{ __('Listings') }}</th><th scope="col"><span class="sr">{{ __('Actions') }}</span></th></tr></thead>
            <tbody>
            @foreach ($sources as $source)
                <tr wire:key="src-{{ $source->id }}">
                    <td>{{ $source->label }}<span class="sub">{{ strtoupper($source->type) }}</span></td>
                    <td>
                        <span class="health {{ $source->health }}">{{ __(ucfirst($source->health)) }}</span>
                        @if ($source->last_error)<span class="sub">{{ $source->last_error }}</span>@endif
                    </td>
                    <td>{{ $source->last_success_at?->diffForHumans() ?? __('Never') }}</td>
                    <td class="num">{{ number_format($source->listings_count) }}</td>
                    <td class="row-actions">
                        <button type="button" class="btn small" wire:click="runNow({{ $source->id }})" @disabled(! $source->enabled)>{{ __('Run now') }}</button>
                        <button type="button" class="btn small quiet" wire:click="toggle({{ $source->id }})">{{ $source->enabled ? __('Pause') : __('Resume') }}</button>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>

    <section class="block">
        <h2>{{ __('Not connected') }}</h2>
        <p class="sub">{{ __('These have no permitted automated route. They stay off rather than being scraped against their terms.') }}</p>
        <table class="plain">
            <thead><tr><th scope="col">{{ __('Site') }}</th><th scope="col">{{ __('Why') }}</th><th scope="col">{{ __('What to do instead') }}</th></tr></thead>
            <tbody>
            @foreach ($unavailable as $name => $info)
                <tr wire:key="off-{{ $loop->index }}"><td>{{ $name }}</td><td>{{ __($info['reason']) }}</td><td>{{ __($info['alternative']) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </section>
</div>
