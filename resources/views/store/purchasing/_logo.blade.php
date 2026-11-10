{{-- The Humana Apparels logo, embedded so the screen, the PDF and a saved copy all show it. --}}
@php
    $logoFile = public_path('images/humana-logo.png');
    $logoData = is_file($logoFile) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoFile)) : null;
@endphp
@if($logoData)
    <img src="{{ $logoData }}" alt="Humana Apparels Pvt. Ltd." style="width:{{ $width ?? 130 }}px;height:auto;">
@else
    <div style="font-size:17px;font-weight:bold;letter-spacing:3px;">HUMANA<br><span style="font-size:7px;letter-spacing:2px;font-weight:normal;">APPARELS PVT. LTD.</span></div>
@endif
