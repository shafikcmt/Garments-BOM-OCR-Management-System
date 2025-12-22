@extends('layouts.app')

@section('content')
<div class="container py-3">

    <h4>Order: {{ $order->order_number }} ({{ ucfirst($order->status) }})</h4>

    <form action="{{ route('admin.orders.storeFieldData', $order->id) }}" method="POST">
        @csrf
        @foreach($sections as $section)
            <div class="card mb-3">
                <div class="card-header bg-primary text-white">
                    {{ $section->name }}
                </div>
                <div class="card-body row g-3">
                    @foreach($section->fields as $field)
                        <div class="col-md-6">
                            <label class="form-label">{{ $field->field_label }}</label>
                            @php
                                $value = $field->values()->where('order_id', $order->id)->where('role_id', Auth::user()->role_id)->first();
                            @endphp

                            @if($field->field_type == 'text')
                                <input type="text" name="fields[{{ $field->id }}]" class="form-control" value="{{ $value->value ?? '' }}" @if($field->is_required) required @endif>
                            @elseif($field->field_type == 'number')
                                <input type="number" name="fields[{{ $field->id }}]" class="form-control" value="{{ $value->value ?? '' }}" @if($field->is_required) required @endif>
                            @elseif($field->field_type == 'select')
                                <select name="fields[{{ $field->id }}]" class="form-select" @if($field->is_required) required @endif>
                                    <option value="">Select</option>
                                    @foreach($field->options as $option)
                                        <option value="{{ $option }}" @if(($value->value ?? '') == $option) selected @endif>{{ $option }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <button class="btn btn-success">Save Data</button>
    </form>

</div>
@endsection
