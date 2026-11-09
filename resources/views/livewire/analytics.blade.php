<div>
    <header class="page-head"><h1>{{ __('Analytics') }}</h1></header>

    <section class="block">
        <h2>{{ __('Discovered per day, last 30 days') }}</h2>
        @php($peak = max([1, ...array_values($trend)]))
        <div class="columns" aria-hidden="true">
            @foreach ($trend as $day => $count)
                <span title="{{ \Illuminate\Support\Carbon::parse($day)->translatedFormat('j M') }}: {{ trans_choice(':count job|:count jobs', $count, ['count' => $count]) }}"><i style="block-size: {{ $count > 0 ? max(3, round(100 * $count / $peak, 1)) : 0 }}%"></i></span>
            @endforeach
        </div>
        <div class="columns-axis" aria-hidden="true"><span>{{ \Illuminate\Support\Carbon::parse(array_key_first($trend))->translatedFormat('j M') }}</span><span>{{ __('Peak :count', ['count' => $peak]) }}</span><span>{{ __('Today') }}</span></div>
        <table class="sr"><caption>{{ __('Jobs discovered per day') }}</caption><tbody>@foreach ($trend as $day => $count)<tr><th scope="row">{{ $day }}</th><td>{{ $count }}</td></tr>@endforeach</tbody></table>
    </section>

    <div class="two">
        <section class="block">
            <h2>{{ __('Most productive sources') }}</h2>
            <table class="plain">
                <thead><tr><th scope="col">{{ __('Source') }}</th><th scope="col" class="num">{{ __('Listings') }}</th><th scope="col" class="num">{{ __('Qualified') }}</th></tr></thead>
                <tbody>@foreach ($sources as $source)<tr wire:key="s-{{ $loop->index }}"><td>{{ $source['label'] }}</td><td class="num">{{ number_format($source['total']) }}</td><td class="num">{{ number_format($source['qualified']) }}</td></tr>@endforeach</tbody>
            </table>
        </section>
        <section class="block"><h2>{{ __('Stated pay, per month') }}</h2><x-bar-list :rows="$salaries" /></section>
        <section class="block"><h2>{{ __('Remote scope') }}</h2><x-bar-list :rows="$regions" /></section>
        <section class="block"><h2>{{ __('Most requested skills') }}</h2><x-bar-list :rows="$skills" /></section>
        <section class="block">
            <h2>{{ __('Application conversion') }}</h2>
            <dl class="facts">
                <div><dt>{{ __('Applied') }}</dt><dd>{{ $conversion['applied'] }}</dd></div>
                <div><dt>{{ __('Reached interview') }}</dt><dd>{{ $conversion['interviews'] }}@if ($conversion['interview_rate'] !== null) ({{ $conversion['interview_rate'] }}%)@endif</dd></div>
                <div><dt>{{ __('Offers') }}</dt><dd>{{ $conversion['offers'] }}@if ($conversion['offer_rate'] !== null) ({{ $conversion['offer_rate'] }}%)@endif</dd></div>
            </dl>
        </section>
        <section class="block"><h2>{{ __('Why jobs were rejected') }}</h2><x-bar-list :rows="$rejections" /></section>
    </div>
</div>
