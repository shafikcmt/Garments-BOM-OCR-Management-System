{{--
    The signature footer of a requisition document — PDF and Excel halves.

    Rendered from the boxes Admin > Settings configured, resolved by
    RequisitionSignatureResolver, so this partial never names a person or a
    label of its own. Shared by the single-requisition document and the
    monthly report so the two footers cannot drift.

    Each box: the caption, a ruled line to sign on, the name, the designation
    under it, and a "Signature & Date" line that carries the date once the
    system knows one — the arrangement the PRA approval sheet uses.

    Expects: $signatures (array from the resolver), $forExcel, and for Excel
    $columns (the sheet's column count, which the boxes share equally).
--}}
@php
    $boxes = array_values($signatures ?? []);
    $count = max(1, count($boxes));

    $signDate = fn ($at) => $at ? 'Signature & Date: '.$at->format('d-M-Y') : 'Signature & Date';
@endphp

@unless($forExcel ?? false)
    <table class="sign">
        <tr>
            @foreach($boxes as $box)
                <td style="width: {{ round(100 / $count, 2) }}%;">
                    <div class="sign-title">{{ $box['label'] }}</div>
                    <div class="sign-line"></div>
                    <div class="sign-meta">
                        <div>{!! $box['name'] !== '' ? e($box['name']) : '&nbsp;' !!}</div>
                        @if($box['title'] !== '')
                            <div class="sign-designation">{{ $box['title'] }}</div>
                        @endif
                        <div>{{ $signDate($box['signed_at']) }}</div>
                    </div>
                </td>
            @endforeach
        </tr>
    </table>
@else
    {{-- The blank rows above the labels are the signing space; bold, borders
         and heights come from FormatsStoreSheet, which finds this block by
         the "Signature & Date" line below the labels. Every box gets the same
         share of the columns; the last takes the remainder. --}}
    @php
        $columns = $columns ?? 21;
        $share = max(1, (int) floor($columns / $count));
        $span = fn ($i) => $i === $count - 1 ? $columns - $share * ($count - 1) : $share;
    @endphp
    <table>
        <tr><td colspan="{{ $columns }}"></td></tr>
        <tr><td colspan="{{ $columns }}"></td></tr>
        <tr><td colspan="{{ $columns }}"></td></tr>
        <tr>
            @foreach($boxes as $i => $box)
                <td colspan="{{ $span($i) }}">{{ $box['label'] }}</td>
            @endforeach
        </tr>
        <tr>
            @foreach($boxes as $i => $box)
                <td colspan="{{ $span($i) }}">{{ trim($box['name'].($box['title'] !== '' ? ($box['name'] !== '' ? ', ' : '').$box['title'] : '')) }}</td>
            @endforeach
        </tr>
        <tr>
            @foreach($boxes as $i => $box)
                <td colspan="{{ $span($i) }}">{{ $signDate($box['signed_at']) }}</td>
            @endforeach
        </tr>
    </table>
@endunless
