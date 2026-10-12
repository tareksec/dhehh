{{-- Disables a quick-add button while its POST is in flight (shared by every theme's product card). --}}
@once
    <script>
        document.addEventListener('submit', function (event) {
            var form = event.target.closest && event.target.closest('[data-quick-add-form]');
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

                var button = form.querySelector('button[type="submit"]');
                if (!button) return;

                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                button.setAttribute('aria-label', button.getAttribute('data-pending-label') || 'Adding to cart');
                button.textContent = '…';
            }, 0);
        });
    </script>
@endonce
