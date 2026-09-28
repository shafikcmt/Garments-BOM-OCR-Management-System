{{--
    The Stock Summary widget, wrapped for a dashboard: its cards and the first
    rows of the Purchase View for the current month, with a link to the full
    card on the Purchase Requisition screen.

    Renders nothing unless ShowsRequisitionSummary built a summary — that is,
    for users holding store.requirement_issue.view (and Admin) — so a dashboard can include it
    unconditionally.

    gx-stock-scope is what the widget's cards and table are styled by, and every
    rule in _stock-ui sits under that class — so this styles the widget and
    touches nothing else on the page it is dropped into.
--}}
@if(($fulfilmentVisible ?? false) && ($stockSummary ?? null))
    <div class="row g-3 mb-4" data-aos="fade-up">
        <div class="col-12">
            <x-card class="gx-fade-in" style="--gx-delay:550ms">
                @include('store.stock._stock-ui')
                <div class="gx-stock-scope">
                    @include('store.stock.requisitions._stock-summary', [
                        'stockSummary' => $stockSummary,
                        'compact' => true,
                    ])
                </div>
            </x-card>
        </div>
    </div>
@endif
