@if ($paginator->hasPages())
    <nav class="pager" aria-label="{{ __('Page :current of :last', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}">
        <button type="button" class="btn small" wire:click="previousPage" @disabled($paginator->onFirstPage())>{{ __('Previous') }}</button>
        <span>{{ __('Page :current of :last', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>
        <button type="button" class="btn small" wire:click="nextPage" @disabled(! $paginator->hasMorePages())>{{ __('Next') }}</button>
    </nav>
@endif
