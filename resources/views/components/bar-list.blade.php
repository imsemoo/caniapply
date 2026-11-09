@props(['rows', 'unit' => ''])

@php($max = max([1, ...array_values($rows)]))

@if ($rows === [])
    <p class="empty">{{ __('No data yet.') }}</p>
@else
    <dl class="bars">
        @foreach ($rows as $label => $value)
            <div>
                <dt dir="auto">{{ __($label) }}</dt>
                <dd>
                    <span class="bar"><i style="inline-size: {{ round(100 * $value / $max, 1) }}%"></i></span>
                    <span class="value">{{ number_format($value) }}{{ $unit }}</span>
                </dd>
            </div>
        @endforeach
    </dl>
@endif
