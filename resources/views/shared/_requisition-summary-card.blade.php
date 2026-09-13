{{--
    The Stock Summary widget, wrapped for a dashboard.

    One include per dashboard rather than the same eight lines copied seven
    times. It renders nothing at all for a user who may not see requisitions, so
    a dashboard can include it unconditionally.

    gx-stock-scope is what the widget's cards and table are styled by, and every
    rule in _stock-ui sits under that class — so this styles the widget and
    touches nothing else on the page it is dropped into.

    Expects the three keys ShowsRequisitionSummary returns.
--}}
@if($fulfilmentVisible ?? false)
    <div class="row g-3 mb-4" data-aos="fade-up">
        <div class="col-12">
            <x-card class="gx-fade-in" style="--gx-delay:550ms">
                @include('store.stock._stock-ui')
                <div class="gx-stock-scope">
                    @include('store.stock.requisitions._fulfilment', [
                        'fulfilment' => $fulfilment,
                        'periodLabel' => $fulfilmentPeriod,
                        'limit' => 8,
                        'moreUrl' => route('store.stock.requisitions.index'),
                    ])
                </div>
            </x-card>
        </div>
    </div>
@endif
