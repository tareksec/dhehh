{{--
    Gallery zoom/thumbnails, purchase double-submit guard and the variant
    quantity counter for products.show. Every theme's product page uses the
    same data-* hooks: data-product-page, data-main-image, data-zoom-frame,
    data-thumbnails (optional data-thumb-class), data-thumb, data-purchase-form,
    data-add-to-cart (data-idle-label / data-count-label), data-variant-row,
    data-variant-qty, data-variant-preview.
--}}
<script>
    (function () {
        var page = document.querySelector('[data-product-page]');
        if (!page) return;

        var mainImage = page.querySelector('[data-main-image]');
        var zoomFrame = page.querySelector('[data-zoom-frame]');
        var thumbsWrap = page.querySelector('[data-thumbnails]');
        var baseThumbsHtml = thumbsWrap ? thumbsWrap.innerHTML : '';

        function resetZoom() {
            if (!mainImage) return;
            mainImage.style.transform = 'scale(1)';
            mainImage.style.transformOrigin = '50% 50%';
        }

        if (zoomFrame && mainImage && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            zoomFrame.addEventListener('pointerenter', function () {
                mainImage.style.transform = 'scale(1.7)';
            });
            zoomFrame.addEventListener('pointermove', function (event) {
                var bounds = zoomFrame.getBoundingClientRect();
                var x = Math.max(0, Math.min(100, ((event.clientX - bounds.left) / bounds.width) * 100));
                var y = Math.max(0, Math.min(100, ((event.clientY - bounds.top) / bounds.height) * 100));
                mainImage.style.transformOrigin = x + '% ' + y + '%';
            });
            zoomFrame.addEventListener('pointerleave', resetZoom);
        }

        function bindThumbs() {
            if (!thumbsWrap || !mainImage) return;
            thumbsWrap.querySelectorAll('[data-thumb]').forEach(function (thumb) {
                thumb.addEventListener('click', function () {
                    resetZoom();
                    mainImage.src = thumb.getAttribute('data-src');
                });
            });
        }

        bindThumbs();

        var purchaseForm = document.querySelector('[data-purchase-form]');
        if (purchaseForm) {
            purchaseForm.addEventListener('submit', function (event) {
                if (purchaseForm.dataset.submitting === 'true') {
                    event.preventDefault();
                    return;
                }

                purchaseForm.dataset.submitting = 'true';

                window.setTimeout(function () {
                    if (event.defaultPrevented) {
                        delete purchaseForm.dataset.submitting;
                        return;
                    }

                    purchaseForm.setAttribute('aria-busy', 'true');
                    document.querySelectorAll('#product-purchase-form button[type="submit"], button[form="product-purchase-form"]').forEach(function (button) {
                        button.disabled = true;
                        button.setAttribute('aria-busy', 'true');
                        if (button.hasAttribute('data-pending-label')) {
                            button.textContent = button.getAttribute('data-pending-label');
                        }
                    });
                }, 0);
            });
        }

        var rows = page.querySelectorAll('[data-variant-row]');
        if (!rows.length) return;

        var addButtons = document.querySelectorAll('[data-add-to-cart]');
        var qtyInputs = page.querySelectorAll('[data-variant-qty]');

        function refreshAddButton() {
            var total = 0;
            qtyInputs.forEach(function (input) {
                total += Math.max(0, parseInt(input.value, 10) || 0);
            });

            addButtons.forEach(function (button) {
                button.disabled = total < 1;
                button.textContent = total > 0
                    ? (button.getAttribute('data-count-label') || 'Add to cart (:count)').replace(':count', total)
                    : (button.getAttribute('data-idle-label') || 'Add to cart');
            });
        }

        refreshAddButton();

        qtyInputs.forEach(function (input) {
            input.addEventListener('input', refreshAddButton);
            input.addEventListener('change', refreshAddButton);
        });

        // Steppers change values programmatically — watch clicks too.
        page.querySelectorAll('[data-variant-rows] [data-qty-decrement], [data-variant-rows] [data-qty-increment]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                setTimeout(refreshAddButton, 0);
            });
        });

        // Clicking a variant's name previews its images.
        rows.forEach(function (row) {
            var preview = row.querySelector('[data-variant-preview]');
            if (!preview) return;

            preview.addEventListener('click', function () {
                var variant = JSON.parse(row.getAttribute('data-variant'));

                if (thumbsWrap && mainImage) {
                    if (variant.images && variant.images.length) {
                        thumbsWrap.classList.remove('hidden');
                        thumbsWrap.innerHTML = variant.images.map(function (src) {
                            return '<button type="button" data-thumb data-src="' + src + '" class="' + (thumbsWrap.getAttribute('data-thumb-class') || 'overflow-hidden rounded-lg border border-gray-200 transition hover:border-[var(--storefront-brand)] dark:border-white/10') + '"><img class="aspect-square w-full object-cover" src="' + src + '" alt="" width="240" height="240" loading="lazy" decoding="async"></button>';
                        }).join('');
                        resetZoom();
                        mainImage.src = variant.images[0];
                    } else {
                        thumbsWrap.innerHTML = baseThumbsHtml;
                        var firstThumb = thumbsWrap.querySelector('[data-thumb]');
                        if (firstThumb) {
                            resetZoom();
                            mainImage.src = firstThumb.getAttribute('data-src');
                        }
                    }
                    bindThumbs();
                }
            });
        });
    })();
</script>
