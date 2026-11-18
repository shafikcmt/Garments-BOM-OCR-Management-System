@extends('layouts.app')

@section('title', 'Email Templates')

@section('styles')
<style>
    .tpl-chip{display:inline-block;background:#e0ecff;color:#1d4ed8;border:1px solid #bfd4ff;border-radius:6px;padding:0 6px;margin:0 1px;font-size:.88em;white-space:nowrap;user-select:all}
    .tpl-surface:focus{box-shadow:0 0 0 .2rem rgba(37,99,235,.15);border-color:#86b7fe;outline:0}
    .tpl-surface p{margin:0 0 .75rem}
</style>
@endsection

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => auth()->user()?->hasRole('admin') ? route('admin.dashboard') : null],
        ['label' => 'Settings'],
        ['label' => 'Email Templates'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center gap-3">
            <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-envelope-paper" aria-hidden="true"></i></span>
            <div>
                <div class="app-hero-eyebrow">Admin / Settings</div>
                <h3 class="app-hero-title mb-0">Email Templates</h3>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success border-0 shadow-sm rounded-3">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger border-0 shadow-sm rounded-3">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <ul class="nav nav-pills mb-3" id="emailTemplateTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="pra-tab" data-bs-toggle="pill" data-bs-target="#pra-pane" type="button" role="tab">
                Payment Request (PRA)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="po-booking-tab" data-bs-toggle="pill" data-bs-target="#po-booking-pane" type="button" role="tab">
                PO Booking to Supplier
            </button>
        </li>
    </ul>

    <div class="tab-content">
        {{-- PRA template --}}
        <div class="tab-pane fade show active" id="pra-pane" role="tabpanel">
            @include('admin.email-templates.partials.form', [
                'type' => 'pra',
                'heading' => 'Payment Request Approval (PRA) Email',
                'description' => 'This template is used to pre-fill the "Send Email" form on a PRA. Senders can still edit the subject and body before sending.',
                'defaultSubject' => 'Payment Request Approval - {{pr_number}}',
                'template' => $praTemplate,
                'placeholders' => $praPlaceholders,
            ])
        </div>

        {{-- PO Booking template --}}
        <div class="tab-pane fade" id="po-booking-pane" role="tabpanel">
            @include('admin.email-templates.partials.form', [
                'type' => 'po_booking',
                'heading' => 'PO Booking to Supplier Email',
                'description' => 'This template pre-fills the "Email to Supplier" form on a generated PO Booking. Senders can still edit the subject and body before sending.',
                'defaultSubject' => 'PO Booking {{po_number}} - {{buyer}} {{season}}',
                'template' => $poBookingTemplate,
                'placeholders' => $poBookingPlaceholders,
            ])
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}
    var esc = function (t) { return String(t).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); };

    document.querySelectorAll('.tpl-editor').forEach(function (box) {
        var labels = {}; try { labels = JSON.parse(box.dataset.labels || '{}'); } catch (e) {}
        var surface = box.querySelector('.tpl-surface'), source = box.querySelector('.tpl-source'), toggle = box.querySelector('.tpl-html-toggle');
        var chip = function (token) {
            return '<span class="tpl-chip" contenteditable="false" data-token="' + esc(token) + '">' + esc(labels[token] || token) + '</span>';
        };
        var toVisual = function (html) { return html.replace(/\{\{\s*[\w.]+\s*\}\}/g, function (m) { return chip(m.replace(/\s+/g, '')); }); };
        var toHtml = function () {
            var clone = surface.cloneNode(true);
            clone.querySelectorAll('.tpl-chip').forEach(function (c) { c.replaceWith(document.createTextNode(c.dataset.token)); });
            var html = clone.innerHTML.replace(/<div>/g, '<p>').replace(/<\/div>/g, '</p>').replace(/&nbsp;/g, ' ').trim();
            return html === '<br>' ? '' : html;
        };
        var sync = function () { if (!toggle.checked) { source.value = toHtml(); } };

        surface.innerHTML = toVisual(source.value);
        surface.addEventListener('input', sync);
        surface.addEventListener('paste', function (e) { e.preventDefault(); document.execCommand('insertText', false, (e.clipboardData || window.clipboardData).getData('text/plain')); });

        box.querySelectorAll('[data-cmd]').forEach(function (b) {
            b.addEventListener('mousedown', function (e) { e.preventDefault(); });
            b.addEventListener('click', function () { surface.focus(); document.execCommand(b.dataset.cmd, false, null); sync(); });
        });
        box.querySelectorAll('[data-token]').forEach(function (b) {
            b.addEventListener('mousedown', function (e) { e.preventDefault(); });
            b.addEventListener('click', function () {
                surface.focus();
                document.execCommand('insertHTML', false, chip(b.dataset.token) + '&nbsp;');
                sync();
            });
        });

        toggle.addEventListener('change', function () {
            if (toggle.checked) { source.value = toHtml(); source.classList.remove('d-none'); surface.classList.add('d-none'); box.querySelector('.tpl-toolbar').classList.add('d-none'); }
            else { surface.innerHTML = toVisual(source.value); source.classList.add('d-none'); surface.classList.remove('d-none'); box.querySelector('.tpl-toolbar').classList.remove('d-none'); }
        });
        box.closest('form').addEventListener('submit', function () { sync(); });
    });
})();
</script>
@endsection
