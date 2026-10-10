{{--
    Double-submit guard for the cart line forms. A theme marks each
    update/remove form with data-cart-line-form and its submit buttons with
    an optional data-pending-label.
--}}
<script>
    document.addEventListener('submit', function (event) {
        var form = event.target.closest && event.target.closest('[data-cart-line-form]');
        if (!form) return;

        if (form.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }

        form.dataset.submitting = 'true';

        window.setTimeout(function () {
            if (event.defaultPrevented) {
                delete form.dataset.submitting;
                return;
            }

            form.setAttribute('aria-busy', 'true');
            form.querySelectorAll('button[type="submit"]').forEach(function (button) {
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                button.textContent = button.getAttribute('data-pending-label') || 'Working…';
            });
        }, 0);
    });
</script>
