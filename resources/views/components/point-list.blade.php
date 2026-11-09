@props(['title', 'items'])

@if ($items !== [])
    <section class="block">
        <h2>{{ $title }}</h2>
        <ul class="points">
            @foreach ($items as $item)
                <li dir="auto">{{ $item }}</li>
            @endforeach
        </ul>
    </section>
@endif
