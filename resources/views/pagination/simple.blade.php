@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="分頁">
        @if ($paginator->onFirstPage())
            <span class="pager-link is-disabled">上一頁</span>
        @else
            <a class="pager-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">上一頁</a>
        @endif

        <span class="pager-status">
            第 {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }} 頁
            （共 {{ number_format($paginator->total()) }} 筆）
        </span>

        @if ($paginator->hasMorePages())
            <a class="pager-link" href="{{ $paginator->nextPageUrl() }}" rel="next">下一頁</a>
        @else
            <span class="pager-link is-disabled">下一頁</span>
        @endif
    </nav>
@endif
