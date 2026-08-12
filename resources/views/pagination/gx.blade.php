{{--
    Pagination, in this application's own language.

    Laravel's default paginator view is `pagination::tailwind`, and nothing here
    ever overrode it — so every paginated screen in a Bootstrap-5 UI was drawing
    a Tailwind paginator. It worked (the utilities are generated; see the vendor
    glob in tailwind.config.js), but it carried `rounded-md`, `ring-gray-300`
    and `dark:` variants that appear nowhere else, and its `justify-between`
    pushed the summary and the page buttons to opposite edges of a
    container-fluid — a hand's width of empty space between them on a wide
    screen.

    Registered as the default in AppServiceProvider, so all seventeen paginated
    screens read the same and no screen needs its own markup.

    Rendered only when there is more than one page, which is the same rule the
    Tailwind view used — this changes how pagination looks, never when it shows.
--}}
@if ($paginator->hasPages())
    <nav class="gx-pagination" role="navigation" aria-label="{{ __('Pagination Navigation') }}">

        {{-- Which slice of the whole set is on screen. Sits with the controls
             rather than at the far edge of the row. --}}
        <p class="gx-pagination-summary">
            Showing <strong>{{ $paginator->firstItem() }}</strong>–<strong>{{ $paginator->lastItem() }}</strong>
            of <strong>{{ number_format($paginator->total()) }}</strong>
        </p>

        <ul class="gx-pagination-list">

            {{-- Previous. A disabled control is a <span>, not a dead link, so it
                 is skipped by keyboard navigation instead of being tabbed to and
                 doing nothing. --}}
            @if ($paginator->onFirstPage())
                <li>
                    <span class="gx-page is-disabled" aria-disabled="true">
                        <i class="bi bi-chevron-left" aria-hidden="true"></i>Prev
                    </span>
                </li>
            @else
                <li>
                    <a class="gx-page" href="{{ $paginator->previousPageUrl() }}" rel="prev">
                        <i class="bi bi-chevron-left" aria-hidden="true"></i>Prev
                    </a>
                </li>
            @endif

            {{-- Page numbers. On a narrow screen these are hidden and the count
                 below carries the position instead — seven number buttons and
                 two arrows do not fit a phone without wrapping into a block. --}}
            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="gx-page-number"><span class="gx-page is-gap" aria-hidden="true">{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <li class="gx-page-number">
                            @if ($page == $paginator->currentPage())
                                <span class="gx-page is-active" aria-current="page">{{ $page }}</span>
                            @else
                                <a class="gx-page" href="{{ $url }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            {{-- The page position, for the narrow screen where the numbers are
                 hidden. Never both — see the media query in components.css. --}}
            <li class="gx-page-of">
                <span class="gx-page is-gap">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            </li>

            @if ($paginator->hasMorePages())
                <li>
                    <a class="gx-page" href="{{ $paginator->nextPageUrl() }}" rel="next">
                        Next<i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </a>
                </li>
            @else
                <li>
                    <span class="gx-page is-disabled" aria-disabled="true">
                        Next<i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </span>
                </li>
            @endif
        </ul>
    </nav>
@endif
