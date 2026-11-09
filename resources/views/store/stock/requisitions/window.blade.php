@extends('layouts.app')

@section('title', 'General Stock — Monthly Requisition Window')

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store'],
        ['label' => 'General Stock'],
        ['label' => 'Purchase Requisition', 'url' => route('store.stock.requisitions.index')],
        ['label' => 'Monthly Window'],
    ]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="calendar-check" eyebrow="General Stock" title="Monthly Requisition Window"
                   copy="Choose when departments may submit a Monthly requisition.">
        <x-slot:actions>
            <a href="{{ route('store.stock.requisitions.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('store._flash')

    <div class="row g-3">
        <div class="col-lg-7">
            <form method="POST" action="{{ route('store.stock.requisitions.window.update') }}" class="card border-0 shadow-sm">
                @csrf
                <div class="card-body p-4">
                    <input type="hidden" name="enabled" value="0">
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" role="switch" id="enabled" name="enabled" value="1" @checked(old('enabled', $enabled ? '1' : '0') === '1')>
                        <label class="form-check-label fw-semibold" for="enabled">Monthly requisition is open for submission</label>
                        <div class="form-text">Switch off to stop all Monthly submissions. Individual requisitions are never affected.</div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold" for="start_day">Opens on day</label>
                            <input type="number" min="1" max="31" class="form-control @error('start_day') is-invalid @enderror" id="start_day" name="start_day" value="{{ old('start_day', $startDay) }}">
                            @error('start_day')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold" for="end_day">Closes on day</label>
                            <input type="number" min="0" max="31" class="form-control @error('end_day') is-invalid @enderror" id="end_day" name="end_day" value="{{ old('end_day', $endDay) }}">
                            <div class="form-text">Enter 0 for the last day of the month.</div>
                            @error('end_day')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="message">Message when blocked <span class="text-muted fw-normal">(optional)</span></label>
                        <textarea class="form-control @error('message') is-invalid @enderror" id="message" name="message" rows="2" maxlength="300" placeholder="Leave empty to use the standard message">{{ old('message', $customMessage) }}</textarea>
                        @error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Save Window</button>
                </div>
            </form>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="text-muted small text-uppercase fw-semibold mb-2">Today</div>
                    @if($open)
                        <span class="badge bg-success-subtle text-success mb-2"><i class="bi bi-unlock me-1" aria-hidden="true"></i>Open</span>
                    @else
                        <span class="badge bg-danger-subtle text-danger mb-2"><i class="bi bi-lock me-1" aria-hidden="true"></i>Closed</span>
                    @endif
                    <p class="mb-3 small">Monthly requisitions are accepted from the {{ $rangeText }}, on the day they are submitted.</p>
                    <div class="text-muted small text-uppercase fw-semibold mb-1">What a department sees when blocked</div>
                    <p class="mb-0 small">{{ $defaultMessage }}</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
