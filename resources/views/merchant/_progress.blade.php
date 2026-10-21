{{-- A labelled percentage bar. $value is 0-100; $tone is a Bootstrap colour name. --}}
<div class="d-flex align-items-center gap-2">
    <div class="progress flex-grow-1" style="height:8px" role="progressbar" aria-valuenow="{{ $value }}" aria-valuemin="0" aria-valuemax="100">
        <div class="progress-bar bg-{{ $tone ?? 'primary' }}" style="width: {{ $value }}%"></div>
    </div>
    <span class="small text-muted" style="min-width:2.5em">{{ $value }}%</span>
</div>
