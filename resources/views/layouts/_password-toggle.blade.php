{{-- Adds a "Show / Hide" text button to every password field on the page.
     Display only: field names, ids and form submission are unchanged. --}}
<script>
(function () {
    function enhance(input) {
        if (input.dataset.pwToggle) return;
        input.dataset.pwToggle = '1';

        var wrap = document.createElement('div');
        wrap.className = 'position-relative';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        input.style.paddingRight = '72px';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-link text-decoration-none fw-semibold position-absolute';
        btn.style.cssText = 'right:6px;top:50%;transform:translateY(-50%);padding:2px 8px;z-index:5;';
        btn.innerHTML = '<i class="bi bi-eye me-1"></i><span>Show</span>';
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.querySelector('i').className = 'bi ' + (show ? 'bi-eye-slash' : 'bi-eye') + ' me-1';
            btn.querySelector('span').textContent = show ? 'Hide' : 'Show';
        });
        wrap.appendChild(btn);
    }
    function run() { document.querySelectorAll('input[type="password"]').forEach(enhance); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
</script>
