@forelse($pendingRows as $row)
    @php
        $preview = $row->booking_preview ?? [];
        $po = $row->bookingPo;
        $status = $row->booking_status ?? ($po ? ($po->status ?: 'applied') : 'pending');
        $isApplied = (bool) $po && $status !== 'completed';
        $needsRegenerate = (bool) ($row->booking_needs_regenerate ?? false);
        $revisionNo = (int) ($row->booking_revision_no ?? 0);
        $qtyValue = array_key_exists('qty', $preview) ? trim((string) $preview['qty']) : '';
        $groupCount = (int) ($row->booking_group_count ?? 1);
        $groupQtyTotal = $row->booking_group_qty_total ?? null;
        $displayQty = $groupCount > 1 && $groupQtyTotal !== null ? $groupQtyTotal : $qtyValue;
        $groupItems = collect($row->booking_group_items ?? [])->filter()->unique()->values();
        if ($isApplied && $po && !empty($po->booking_data['items'])) {
            $groupCount = count($po->booking_data['items']);
            $groupItems = collect($po->booking_data['items'])->pluck('item_name')->filter()->unique()->values();
            $displayQty = $po->qty !== null && $po->qty !== '' ? $po->qty : $displayQty;
        }
    @endphp
    <tr data-row-id="{{ $row->id }}" data-po-id="{{ $po?->id }}" data-status="{{ $isApplied ? 'applied' : 'pending' }}">
        <td class="text-center">
            <input type="checkbox"
                   class="form-check-input booking-row-check"
                   value="{{ $row->id }}"
                   data-po-id="{{ $po?->id }}"
                   data-status="{{ $isApplied ? 'applied' : 'pending' }}">
        </td>
        <td>
            <div class="fw-bold text-slate-900">{{ $preview['buyer_name'] ?? '-' }}</div>
        </td>
        <td><span class="fw-semibold text-slate-700">{{ $preview['season_name'] ?? '-' }}</span></td>
        <td>{{ $preview['ihod'] ?? '-' }}</td>
        <td>
            <div class="fw-semibold">{{ $preview['vendor_name'] ?? '-' }}</div>
        </td>
        <td class="item-cell">
            <div class="fw-bold text-slate-900">{{ $groupItems->isNotEmpty() ? $groupItems->take(3)->implode(', ') : ($preview['item_name'] ?? '-') }}</div>
            <div class="text-muted small">Style: {{ $preview['style_name'] ?? '-' }}</div>
        </td>
        <td class="text-end fw-bold text-slate-900">{{ $displayQty !== '' && $displayQty !== null ? $displayQty : '-' }}</td>
        <td class="text-center action-cell">
            @if($isApplied)
                <div class="d-inline-flex align-items-center justify-content-center gap-2 booking-action-wrap">
                    <span class="po-mini-pill" title="{{ $po->po_no }}"><i class="bi bi-upc-scan"></i>{{ $po->po_no }}</span>
                    @if($revisionNo > 0)
                        <span class="revision-mini-pill">R{{ $revisionNo }}</span>
                    @endif
                    <div class="dropdown">
                        <button class="btn booking-kebab-btn btn-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="More actions">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end booking-action-dropdown">
                            <li><a href="{{ route('supply_chain.bookings.show', $po) }}" class="dropdown-item"><i class="bi bi-eye me-2"></i>View booking</a></li>
                            <li><a href="{{ route('supply_chain.bookings.print', $po) }}" target="_blank" class="dropdown-item"><i class="bi bi-printer me-2"></i>Print</a></li>
                            <li><a href="{{ route('supply_chain.bookings.download', $po) }}" target="_blank" class="dropdown-item"><i class="bi bi-filetype-pdf me-2"></i>Download PDF</a></li>
                            <li><a href="{{ route('supply_chain.bookings.download_excel', $po) }}" target="_blank" class="dropdown-item"><i class="bi bi-file-earmark-excel me-2"></i>Download Excel</a></li>
                        </ul>
                    </div>
                </div>
            @else
                <div class="d-inline-flex align-items-center justify-content-center gap-2 booking-action-wrap">
                    <button type="button"
                            class="btn preview-outline-btn btn-sm preview-single-btn"
                            data-url="{{ route('supply_chain.bookings.preview', $row) }}">
                        <i class="bi bi-eye me-1"></i>Preview
                    </button>
                    <div class="dropdown">
                        <button class="btn booking-kebab-btn btn-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="More actions">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end booking-action-dropdown">
                            <li>
                                <button type="button" class="dropdown-item preview-single-btn" data-url="{{ route('supply_chain.bookings.preview', $row) }}">
                                    <i class="bi bi-eye me-2"></i>Preview booking
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            @endif
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8">
            <div class="booking-empty text-center text-muted">
                <div>
                    <span class="d-inline-flex align-items-center justify-content-center rounded-5 bg-light border mb-3" style="width:78px;height:78px;">
                        <i class="bi bi-inbox fs-1 text-slate-300"></i>
                    </span>
                    <div class="fw-bold text-slate-700">No pending order found</div>
                    <div class="small text-muted">Try changing filter options or keyword search.</div>
                </div>
            </div>
        </td>
    </tr>
@endforelse
