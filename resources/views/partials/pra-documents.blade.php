@php $praDocs = $paymentRequest->attachments(); @endphp
<div class="card border-0 shadow-sm mb-4 pra-no-print" style="border-radius:var(--gx-radius);">
    <div class="card-body p-4">
        <h5 class="mb-3"><i class="bi bi-paperclip me-1" aria-hidden="true"></i> Supporting Documents ({{ count($praDocs) }})</h5>
        @forelse($praDocs as $i => $doc)
            <div class="d-flex justify-content-between align-items-center gap-2 border rounded-3 px-3 py-2 mb-2">
                <div class="min-w-0">
                    <div class="fw-semibold small text-truncate">{{ $doc['name'] }}</div>
                    <div class="small text-muted">{{ number_format(($doc['size'] ?? 0) / 1024, 0) }} KB @if(!empty($doc['uploaded_at'])) · {{ \Illuminate\Support\Carbon::parse($doc['uploaded_at'])->format('d M Y') }} @endif</div>
                </div>
                <div class="d-flex gap-1 flex-shrink-0">
                    @if(in_array(strtolower(pathinfo($doc['name'], PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png'], true))
                        <a href="{{ route('pra_documents.view', [$paymentRequest, $i]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary"><i class="bi bi-eye me-1" aria-hidden="true"></i>View</a>
                    @endif
                    <a href="{{ route('pra_documents.download', [$paymentRequest, $i]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-download me-1" aria-hidden="true"></i>Download</a>
                </div>
            </div>
        @empty
            <p class="text-muted small mb-0">No documents attached to this PRA.</p>
        @endforelse
    </div>
</div>
