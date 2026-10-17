{{--
    Checkout form behaviour: autosave of the contact details (when the owner
    turned it on), the leave-page guard, the error summary focus and the
    double-submit lock. Needs $page (StorefrontCheckoutPage). Marks used:
    #checkout-form, [data-checkout-submit] (its optional data-pending-label is
    the text while sending) and [data-checkout-errors].
--}}
<script>
    (function () {
        var form = document.getElementById('checkout-form');
        var submitButtons = document.querySelectorAll('[data-checkout-submit]');
        if (! form) return;

        var dirty = false;
        var autosaveUrl = {{ Illuminate\Support\Js::from($page->autosaveUrl) }};
        var autosaveTimer = null;

        function checkoutDetails() {
            return ['name', 'phone', 'email', 'address'].reduce(function (details, field) {
                var input = form.elements.namedItem(field);
                details[field] = input ? input.value : '';
                return details;
            }, {});
        }

        function autosave() {
            if (! autosaveUrl) return;
            var token = form.querySelector('input[name="_token"]');
            fetch(autosaveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token ? token.value : ''
                },
                body: JSON.stringify(checkoutDetails())
            }).catch(function () {});
        }

        form.addEventListener('input', function (event) {
            dirty = true;
            if (! autosaveUrl || ! ['name', 'phone', 'email', 'address'].includes(event.target.name)) return;
            window.clearTimeout(autosaveTimer);
            autosaveTimer = window.setTimeout(autosave, 1200);
        });

        var errorSummary = document.querySelector('[data-checkout-errors]');
        if (errorSummary) {
            window.requestAnimationFrame(function () { errorSummary.focus(); });
        }

        form.addEventListener('submit', function () {
            dirty = false;
            window.clearTimeout(autosaveTimer);
            submitButtons.forEach(function (button) {
                button.disabled = true;
                button.textContent = button.getAttribute('data-pending-label') || 'Processing…';
            });
        });

        window.addEventListener('pagehide', function () {
            window.clearTimeout(autosaveTimer);
            if (dirty) autosave();
        });

        window.addEventListener('beforeunload', function (event) {
            if (! dirty) return;
            event.preventDefault();
            event.returnValue = '';
        });
    })();
</script>
