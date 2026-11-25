{{-- Pagination en francais, sans dependance de style. --}}
@if ($paginator->total() > 0)
    <nav class="pagination" aria-label="Pages">
        <span>
            {{ $paginator->firstItem() }} à {{ $paginator->lastItem() }} sur {{ $paginator->total() }}
        </span>
        @if ($paginator->hasPages())
            <span class="pagination-liens">
                @if ($paginator->onFirstPage())
                    <span class="bouton bouton-secondaire bouton-petit" aria-disabled="true" style="opacity:.45">← Précédent</span>
                @else
                    <a class="bouton bouton-secondaire bouton-petit" href="{{ $paginator->previousPageUrl() }}" rel="prev">← Précédent</a>
                @endif
                <span style="align-self:center">Page {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
                @if ($paginator->hasMorePages())
                    <a class="bouton bouton-secondaire bouton-petit" href="{{ $paginator->nextPageUrl() }}" rel="next">Suivant →</a>
                @else
                    <span class="bouton bouton-secondaire bouton-petit" aria-disabled="true" style="opacity:.45">Suivant →</span>
                @endif
            </span>
        @endif
    </nav>
@endif
