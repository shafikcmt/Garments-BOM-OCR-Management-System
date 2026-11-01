{{-- Monthly / Individual badge for a department's sheet. The Store's own Final sheet is neither. --}}
@if($r->isDepartmentLevel())
    <span class="badge ms-1 {{ $r->isIndividual() ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary-emphasis' }}">{{ \App\Models\PurchaseRequisition::typeLabels()[$r->requisition_type] ?? 'Monthly' }}</span>
@endif
