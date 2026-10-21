@php($labels = [1 => 'Choose file', 2 => 'Match columns', 3 => 'Preview and import'])
<ol class="list-unstyled d-flex flex-wrap gap-2 mb-3">
    @foreach($labels as $n => $label)
        <li>
            <span class="badge {{ $n === $current ? 'bg-primary' : ($n < $current ? 'bg-success-subtle text-success' : 'bg-light text-muted border') }} px-3 py-2">
                @if($n < $current)<i class="bi bi-check-lg me-1" aria-hidden="true"></i>@endif{{ $n }}. {{ $label }}
            </span>
        </li>
    @endforeach
</ol>
