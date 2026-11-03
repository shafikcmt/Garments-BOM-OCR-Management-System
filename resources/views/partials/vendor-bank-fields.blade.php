{{-- Bank details block shared by Admin vendors, Merchant vendor entry and Store suppliers.
     Expects $bank (model or null); optional $colClass overrides the column width. All optional. --}}
@php
    $fields = [
        'bank_name' => ['Bank Name', 'col-md-4', 255],
        'bank_branch' => ['Branch', 'col-md-4', 255],
        'bank_account_name' => ['Account Name', 'col-md-4', 255],
        'bank_account_number' => ['Account Number', 'col-md-4', 100],
        'bank_routing_number' => ['Routing Number', 'col-md-4', 100],
        'bank_swift_code' => ['SWIFT Code', 'col-md-4', 50],
    ];
@endphp
<div class="col-12">
    <h6 class="mt-2 mb-0"><i class="bi bi-bank me-1" aria-hidden="true"></i>Bank Details</h6>
    <div class="text-muted small">Optional. Used for payment requests.</div>
</div>
@foreach($fields as $name => [$label, $col, $max])
    <div class="{{ $colClass ?? $col }}">
        <label class="form-label" for="{{ $name }}">{{ $label }}</label>
        <input id="{{ $name }}" type="text" name="{{ $name }}" maxlength="{{ $max }}"
               class="form-control @error($name) is-invalid @enderror"
               value="{{ old($name, $bank->{$name} ?? '') }}">
        @error($name)<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
@endforeach
