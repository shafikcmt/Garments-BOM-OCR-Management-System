@if($pendingRows->total() > 0)
    @php
        $current = $pendingRows->currentPage();
        $last = $pendingRows->lastPage();
        $start = max(1, $current - 2);
        $end = min($last, $current + 2);
    @endphp
    <div class="booking-pagination">
        <div class="small text-muted fw-semibold">
            Showing <span class="fw-bold text-slate-900">{{ $pendingRows->firstItem() }}</span>
            to <span class="fw-bold text-slate-900">{{ $pendingRows->lastItem() }}</span>
            of <span class="fw-bold text-slate-900">{{ $pendingRows->total() }}</span> results
        </div>

        @if($pendingRows->hasPages())
            <div class="page-group">
                @if($pendingRows->onFirstPage())
                    <span class="page-num disabled"><i class="bi bi-chevron-left me-1"></i> Prev</span>
                @else
                    <a href="{{ $pendingRows->previousPageUrl() }}" rel="prev"><i class="bi bi-chevron-left me-1"></i> Prev</a>
                @endif

                @if($start > 1)
                    <a href="{{ $pendingRows->url(1) }}">1</a>
                    @if($start > 2)
                        <span class="page-num disabled">...</span>
                    @endif
                @endif

                @for($page = $start; $page <= $end; $page++)
                    @if($page == $current)
                        <span class="page-num active">{{ $page }}</span>
                    @else
                        <a href="{{ $pendingRows->url($page) }}">{{ $page }}</a>
                    @endif
                @endfor

                @if($end < $last)
                    @if($end < $last - 1)
                        <span class="page-num disabled">...</span>
                    @endif
                    <a href="{{ $pendingRows->url($last) }}">{{ $last }}</a>
                @endif

                @if($pendingRows->hasMorePages())
                    <a href="{{ $pendingRows->nextPageUrl() }}" rel="next">Next <i class="bi bi-chevron-right ms-1"></i></a>
                @else
                    <span class="page-num disabled">Next <i class="bi bi-chevron-right ms-1"></i></span>
                @endif
            </div>
        @endif
    </div>
@endif
