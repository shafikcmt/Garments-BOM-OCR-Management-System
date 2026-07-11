@foreach (['success' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $key => $variant)
    @if(session($key))
        <div class="alert alert-{{ $variant }} border-0 shadow-sm rounded-3">{{ session($key) }}</div>
    @endif
@endforeach
@if($errors->any())
    <div class="alert alert-danger border-0 shadow-sm rounded-3">
        <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif
