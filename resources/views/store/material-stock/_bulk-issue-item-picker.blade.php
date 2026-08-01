{{-- Item picker. Shared by the index page's slide-in panel and the
     full-page create/edit route, which never render together — so the same
     element ids drive both from one JavaScript module. --}}
{{-- Two-level item picker, same shape as Receiving's: Style first, then the
     item(s) under each style. A style can carry one item or several, which a
     flat list made hard to read. A single-style PO still shows the style step —
     pre-ticked — because issuing against the wrong style is not recoverable. --}}
<div class="modal fade" id="biItemsModal" tabindex="-1" aria-labelledby="biItemsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:var(--gx-radius);">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0" id="biItemsModalLabel">Select Items to Issue</h5>
                    <div class="small text-muted" id="biModalPo">—</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <ol class="bi-steps" id="biSteps">
                    <li class="bi-step is-current" id="biCrumb1">
                        <span class="bi-step-dot"><i class="bi bi-check-lg" aria-hidden="true"></i><span class="bi-step-num">1</span></span>
                        <span class="bi-step-label">
                            <span class="bi-step-caption">Step 1</span>
                            <span class="bi-step-text">Choose Style</span>
                        </span>
                    </li>
                    <li class="bi-step" id="biCrumb2">
                        <span class="bi-step-dot"><i class="bi bi-check-lg" aria-hidden="true"></i><span class="bi-step-num">2</span></span>
                        <span class="bi-step-label">
                            <span class="bi-step-caption">Step 2</span>
                            <span class="bi-step-text">Choose Items</span>
                        </span>
                    </li>
                </ol>

                <div id="biModalLoading" class="text-center text-muted py-5">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading items…
                </div>
                <div id="biModalError" class="alert alert-warning d-none mb-0"></div>

                {{-- Filter + Select all. Both act on whichever step is showing,
                     so one toolbar serves the style list and the item list. --}}
                <div class="bi-pick-bar d-none" id="biPickBar">
                    <div class="input-group bi-pick-filter">
                        <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                        <input type="text" class="form-control" id="biFilter" autocomplete="off"
                               placeholder="Filter this list…" aria-label="Filter the list below">
                    </div>
                    <span class="bi-showing" id="biShowing" aria-live="polite"></span>
                    <button type="button" class="btn btn-link bi-pick-clear d-none" id="biFilterClear">Clear filter</button>
                    <button type="button" class="btn btn-outline-primary bi-selectall" id="biSelectAllBtn">
                        <span id="biSelectAllText">Select all</span>
                    </button>
                </div>

                <div class="bi-nomatch d-none" id="biNoMatch">
                    <i class="bi bi-search me-1" aria-hidden="true"></i>Nothing matches that filter.
                </div>

                {{-- Level 1: styles --}}
                <div id="biStep1" class="d-none">
                    <div class="bi-pick table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="sticky-top">
                                <tr>
                                    <th style="width:42px;"><input type="checkbox" class="form-check-input" id="biStyleAll" title="Select all styles" aria-label="Select all styles"></th>
                                    <th>Style Number</th>
                                    <th style="width:110px;" class="text-end">Items</th>
                                    <th style="width:150px;" class="text-end">Available Stock</th>
                                </tr>
                            </thead>
                            <tbody id="biStyleBody"></tbody>
                        </table>
                    </div>
                </div>

                {{-- Level 2: items within the chosen styles. Available stock is
                     what Bulk Issuing cares about — it takes stock out, so the
                     ledger's running balance replaces Receiving's ordered qty. --}}
                <div id="biStep2" class="d-none">
                    {{-- One column per field, matching the Full Table's set, so
                         the same column filter can sit on each header. Wider
                         than the modal on purpose — the picker scrolls
                         sideways rather than packing four fields into a cell
                         that cannot be filtered on its own. --}}
                    <div class="bi-pick bi-pick-wide table-responsive">
                        <table class="table table-sm align-middle mb-0" id="biItemTable">
                            <thead class="sticky-top">
                                <tr>
                                    <th style="width:42px;"><input type="checkbox" class="form-check-input" id="biItemAll" title="Select all available items" aria-label="Select all available items"></th>
                                    @foreach([
                                        ['key' => 'style_name', 'label' => 'Style'],
                                        ['key' => 'material_name', 'label' => 'Material Name'],
                                        ['key' => 'material_description', 'label' => 'Material Description', 'wide' => true],
                                        ['key' => 'art_no', 'label' => 'Art. No'],
                                        ['key' => 'sap_code', 'label' => 'SAP Code'],
                                        ['key' => 'gmts_color_name', 'label' => 'GMTS Color Name'],
                                        ['key' => 'material_color', 'label' => 'Material Color'],
                                        ['key' => 'size', 'label' => 'Size'],
                                        ['key' => 'uom', 'label' => 'Unit'],
                                        ['key' => 'available', 'label' => 'Available', 'num' => true],
                                    ] as $pcol)
                                        <th data-bi-pcol="{{ $pcol['key'] }}"
                                            class="{{ ($pcol['wide'] ?? false) ? 'bi-ft-wide' : '' }} {{ ($pcol['num'] ?? false) ? 'bi-ft-num' : '' }}">
                                            <div class="bi-ft-head">
                                                <span class="bi-ft-label">{{ $pcol['label'] }}</span>
                                                {{-- Menu contents are built by the picker JS through the
                                                     same shared filter state the Full Table uses. --}}
                                                <button type="button" class="bi-ft-fbtn" data-bi-pfilter="{{ $pcol['key'] }}"
                                                        aria-label="Filter {{ $pcol['label'] }}" aria-expanded="false">
                                                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            </div>
                                            <div class="bi-ft-menu d-none" data-bi-pmenu="{{ $pcol['key'] }}"></div>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody id="biItemBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="modal-footer bi-modal-footer">
                <span class="bi-selcount me-auto" id="biSelCountWrap">
                    <span class="bi-selcount-badge" id="biPickCount">0</span>
                    <span id="biPickCountLabel">selected</span>
                </span>
                <button type="button" class="btn btn-outline-secondary d-none" id="biBackBtn"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back</button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                {{-- Back / Next / Confirm, the same three words the outer wizard
                     uses, so one vocabulary covers the whole multi-step flow. --}}
                <button type="button" class="btn btn-primary" id="biNextBtn">Next<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></button>
                <button type="button" class="btn btn-primary d-none" id="biAddSelected"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Confirm Selection</button>
            </div>
        </div>
    </div>
</div>
