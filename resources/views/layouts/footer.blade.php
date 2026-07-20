{{-- Minimal footer: who owns the system and which build is running.
     The version is read from config so it is set in one place. --}}
<footer class="gx-footer">
    <span>&copy; {{ date('Y') }} Humana Apparels Pvt. Ltd. All rights reserved.</span>
    <span>
        OCR Management
        <span class="text-muted">&middot;</span>
        v{{ config('app.version', '1.0.0') }}
    </span>
</footer>
