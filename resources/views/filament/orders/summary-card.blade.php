{{--
    Order summary card (ViewOrder → "Order summary card"). The card is drawn
    as a PNG on a canvas; the preview below IS that image, and the app
    buttons send the image itself:
    - Android app: window.ZzShareBridge (android/.../ShareBridge.java) hands
      the PNG to WhatsApp (straight into the customer's chat), WeChat,
      Messenger or Telegram, and saves it to the gallery.
    - Phone browser: the system share sheet with the image attached.
    - Computer: the image is downloaded and the app's web version opens so
      it can be attached there.
--}}
<div
    x-data="{
        card: @js($card),
        messages: @js([
            'notInstalled' => __('That app is not installed on this phone.'),
            'updateApp' => __('Install the latest version of the app to send and save images.'),
            'saved' => __('Image saved to your gallery (Pictures/ZamZam).'),
            'downloaded' => __('The image was downloaded — attach it in the chat.'),
            'failed' => __('Could not create the image. Please try again.'),
            'copied' => __('Summary copied.'),
        ]),
        notice: '',
        previewUrl: '',
        images: [],
        logoImage: null,
        canShareFiles: false,
        init() {
            try {
                const probe = new File([new Blob(['x'], { type: 'image/png' })], 'probe.png', { type: 'image/png' });
                this.canShareFiles = !! (navigator.canShare && navigator.canShare({ files: [probe] }));
            } catch (e) {
                this.canShareFiles = false;
            }
            this.$nextTick(async () => {
                try {
                    await this.loadImages();
                    this.previewUrl = this.draw().toDataURL('image/png');
                } catch (e) {
                    this.say(this.messages.failed);
                }
            });
        },
        // Product pictures arrive as small data URIs; they must be decoded
        // before the canvas is drawn. A picture that fails is simply skipped.
        loadImages() {
            const load = (src, done) => new Promise((resolve) => {
                if (! src) return resolve();
                const img = new Image();
                img.onload = () => { done(img); resolve(); };
                img.onerror = () => resolve();
                img.src = src;
            });
            return Promise.all([
                load(this.card.logo, (img) => { this.logoImage = img; }),
                ...this.card.items.map((item, index) => load(item.image, (img) => { this.images[index] = img; })),
            ]);
        },
        bridge() {
            const bridge = window.ZzShareBridge;
            return bridge && typeof bridge.shareImage === 'function' ? bridge : null;
        },
        inOldApp() {
            return ! this.bridge() && !! (window.ZzNativeBridge || window.ZzPrintBridge
                || (window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform()));
        },
        say(message) {
            this.notice = message;
            clearTimeout(this.noticeTimer);
            this.noticeTimer = setTimeout(() => this.notice = '', 6000);
        },
        base64() {
            return (this.previewUrl || this.draw().toDataURL('image/png')).split(',')[1];
        },
        async file() {
            const blob = await (await fetch(this.previewUrl || this.draw().toDataURL('image/png'))).blob();
            return new File([blob], this.card.file_name, { type: 'image/png' });
        },
        async send(target) {
            const bridge = this.bridge();
            if (bridge) {
                const phone = target === 'whatsapp' ? (this.card.customer_phone || '') : '';
                const result = bridge.shareImage(this.base64(), this.card.file_name, target, phone);
                if (result === 'not_installed') this.say(this.messages.notInstalled);
                if (result === 'error') this.say(this.messages.failed);
                return;
            }
            if (this.inOldApp()) {
                this.say(this.messages.updateApp);
                return;
            }
            if (this.canShareFiles) {
                try {
                    await navigator.share({ files: [await this.file()] });
                } catch (e) {}
                return;
            }
            const webApps = {
                whatsapp: this.card.customer_phone ? 'https://wa.me/' + this.card.customer_phone : 'https://web.whatsapp.com/',
                wechat: 'https://web.wechat.com/',
                messenger: 'https://www.messenger.com/',
                telegram: 'https://web.telegram.org/',
            };
            this.browserDownload();
            window.open(webApps[target], '_blank', 'noopener');
            this.say(this.messages.downloaded);
        },
        download() {
            const bridge = this.bridge();
            if (bridge) {
                const result = bridge.saveImage(this.base64(), this.card.file_name);
                if (result === 'saved') this.say(this.messages.saved);
                if (result === 'error') this.say(this.messages.failed);
                return;
            }
            if (this.inOldApp()) {
                this.say(this.messages.updateApp);
                return;
            }
            this.browserDownload();
        },
        browserDownload() {
            const link = document.createElement('a');
            link.href = this.previewUrl || this.draw().toDataURL('image/png');
            link.download = this.card.file_name;
            document.body.appendChild(link);
            link.click();
            link.remove();
        },
        async copyText() {
            try {
                await navigator.clipboard.writeText(this.card.text);
            } catch (e) {
                const area = document.createElement('textarea');
                area.value = this.card.text;
                document.body.appendChild(area);
                area.select();
                document.execCommand('copy');
                area.remove();
            }
            this.say(this.messages.copied);
        },
        wrap(ctx, text, maxWidth) {
            const lines = [];
            let line = '';
            for (const word of String(text).split(' ')) {
                const test = line ? line + ' ' + word : word;
                if (ctx.measureText(test).width > maxWidth && line) {
                    lines.push(line);
                    line = word;
                } else {
                    line = test;
                }
            }
            if (line) lines.push(line);
            return lines.length ? lines : [''];
        },
        draw() {
            const W = 1080, pad = 56, inner = W - pad * 2;
            const navy = '#0f2a43', accent = '#ff6a00', ink = '#0f172a', muted = '#64748b', line = '#e2e8f0', soft = '#f8fafc', soft2 = '#f1f5f9', green = '#16a34a';
            const font = (size, weight) => (weight || 400) + ' ' + size + 'px system-ui, -apple-system, Roboto, \'Segoe UI\', \'Noto Sans Bengali\', sans-serif';
            const ctx0 = document.createElement('canvas').getContext('2d');
            const ops = [];
            const text = (value, size, weight, color, x, y, align, spacing) => ops.push({ t: 'text', value, size, weight, color, x, y, align: align || 'left', spacing: spacing || 0 });
            const rect = (x, y, w, h, color, r, stroke) => ops.push({ t: 'rect', x, y, w, h, color, r: r || 0, stroke: stroke || null });
            const lines = (value, size, weight, maxWidth) => {
                ctx0.font = font(size, weight);
                return this.wrap(ctx0, value, maxWidth);
            };
            // Wrapped paragraph; the first baseline sits `size` below y. Returns its height.
            const para = (value, size, weight, color, x, y, maxWidth, lead, align) => {
                const parts = lines(value, size, weight, maxWidth);
                parts.forEach((part, i) => text(part, size, weight, color, x, y + size + i * lead, align || 'left'));
                return parts.length * lead;
            };
            const card = this.card;

            // 1. Header: logo (or the company name) on the left, the company's contact details
            // (phone, e-mail, website, address) on the right - the only place they appear.
            const top = 44;
            const headerLines = [
                ...card.contact.slice(0, 3).map((part) => ({ value: part, size: 26 })),
                ...(card.address ? lines(card.address, 24, 500, 430).map((part) => ({ value: part, size: 24 })) : []),
            ];
            const headerH = Math.max(132, headerLines.length * 36 + 8);
            const logo = this.logoImage;
            if (logo) {
                const s = Math.min(460 / logo.naturalWidth, 120 / logo.naturalHeight, 4);
                const lw = logo.naturalWidth * s, lh = logo.naturalHeight * s;
                ops.push({ t: 'img', img: logo, x: pad, y: top + (headerH - lh) / 2, w: lw, h: lh, r: 0 });
            } else {
                const nameLines = lines(card.company, 46, 800, 540).slice(0, 2);
                const nameTop = top + (headerH - nameLines.length * 58) / 2;
                nameLines.forEach((part, i) => text(part, 46, 800, navy, pad, nameTop + 44 + i * 58));
            }
            const headerTop = top + (headerH - headerLines.length * 36) / 2;
            headerLines.forEach((part, i) => text(part.value, part.size, 500, muted, W - pad, headerTop + 27 + i * 36, 'right'));

            // 2. Title band with the invoice number.
            const bandY = top + headerH + 14;
            rect(0, bandY, W, 76, navy);
            rect(0, bandY + 76, W, 6, accent);
            text(card.title.toUpperCase(), 28, 700, '#ffffff', pad, bandY + 49, 'left', 3);
            text(card.labels.invoice + '  ' + card.invoice, 28, 600, '#ffffff', W - pad, bandY + 49, 'right');

            // 3. Order details and customer details, side by side.
            const gap = 28, cardW = (inner - gap) / 2, cardPad = 26, labelW = 162;
            const cardsY = bandY + 76 + 6 + 34;
            const rowsHeight = (rows) => rows.reduce((h, row) => {
                if (row.key === 'address') {
                    return h + 28 + lines(row.value, 27, 600, cardW - cardPad * 2).length * 36 + 14;
                }
                return h + Math.max(34, lines(row.value, 27, 600, cardW - cardPad * 2 - labelW).length * 36) + 14;
            }, 0);
            const payRowH = 58;
            const cardH = 70 + Math.max(rowsHeight(card.order_rows) + payRowH, rowsHeight(card.customer_rows)) + 6;
            const drawCard = (x, title, rows) => {
                rect(x, cardsY, cardW, cardH, soft, 18, line);
                rect(x + cardPad, cardsY + 22, 6, 26, accent, 3);
                text(title.toUpperCase(), 22, 800, navy, x + cardPad + 20, cardsY + 43, 'left', 2);
                let ry = cardsY + 70;
                rows.forEach((row) => {
                    if (row.key === 'address') {
                        text(row.label, 23, 500, muted, x + cardPad, ry + 22);
                        ry += 28;
                        ry += para(row.value, 27, 600, ink, x + cardPad, ry - 2, cardW - cardPad * 2, 36) + 14;
                        return;
                    }
                    text(row.label, 23, 500, muted, x + cardPad, ry + 26);
                    const bold = row.key === 'customer';
                    ry += Math.max(34, para(row.value, bold ? 30 : 27, bold ? 700 : 600, ink, x + cardPad + labelW, ry - 2, cardW - cardPad * 2 - labelW, 36)) + 14;
                });
            };
            drawCard(pad, card.labels.order_details, card.order_rows);
            drawCard(pad + cardW + gap, card.labels.customer_details, card.customer_rows);

            // Payment status as a coloured pill, the last row of the order card.
            const pay = card.payment;
            const palette = { paid: ['#dcfce7', '#15803d'], partial: ['#fef3c7', '#b45309'], unpaid: ['#fee2e2', '#b91c1c'] }[pay.key] || ['#e2e8f0', ink];
            const payY = cardsY + 70 + rowsHeight(card.order_rows);
            ctx0.font = font(22, 700);
            const pillW = ctx0.measureText(pay.label).width + 44;
            text(card.labels.payment, 23, 500, muted, pad + cardPad, payY + 32);
            rect(pad + cardPad + labelW, payY, pillW, 42, palette[0], 21);
            text(pay.label, 22, 700, palette[1], pad + cardPad + labelW + pillW / 2, payY + 29, 'center');

            let y = cardsY + cardH + 34;

            // 4. Items table: the picture of every product the customer ordered.
            if (card.items.length) {
                const priceR = 745, qtyC = 830, amountR = W - pad - 24;
                rect(pad, y, inner, 60, navy, 14);
                text(card.labels.item.toUpperCase(), 21, 700, '#ffffff', pad + 24, y + 38, 'left', 1.5);
                text(card.labels.price.toUpperCase(), 21, 700, '#ffffff', priceR, y + 38, 'right', 1.5);
                text(card.labels.quantity.toUpperCase(), 21, 700, '#ffffff', qtyC, y + 38, 'center', 1.5);
                text(card.labels.amount.toUpperCase(), 21, 700, '#ffffff', amountR, y + 38, 'right', 1.5);
                y += 60;
                const thumb = 96;
                const hasThumbs = card.items.some((item) => item.image);
                const textX = pad + 24 + (hasThumbs ? thumb + 20 : 0);
                const textW = priceR - 190 - textX;
                card.items.forEach((item, i) => {
                    const nameH = lines(item.name, 28, 600, textW).length * 38;
                    const rowH = Math.max(nameH, hasThumbs ? thumb : 0) + 32;
                    if (i % 2 === 1) rect(pad, y, inner, rowH, soft);
                    if (hasThumbs) {
                        ops.push({ t: 'img', img: this.images[i] || null, x: pad + 24, y: y + (rowH - thumb) / 2, w: thumb, h: thumb, r: 12, border: line });
                    }
                    para(item.name, 28, 600, ink, textX, y + (rowH - nameH) / 2 - 4, textW, 38);
                    const mid = y + rowH / 2 + 10;
                    text(item.price, 26, 500, muted, priceR, mid, 'right');
                    text(String(item.quantity), 28, 700, ink, qtyC, mid, 'center');
                    text(item.amount, 28, 700, ink, amountR, mid, 'right');
                    y += rowH;
                    rect(pad, y - 1, inner, 2, line);
                });
                y += 30;
            }

            // 5. Order note on the left, totals on the right.
            const totalsW = 440, totalsX = W - pad - totalsW;
            const sideW = totalsX - pad - 28;
            const sideCards = [];
            if (card.note) sideCards.push({ title: card.labels.note, value: card.note });
            const sideHeight = (part) => 62 + lines(part.value, 25, 500, sideW - cardPad * 2).length * 34 + 22;
            let sideH = sideCards.reduce((h, part, i) => h + sideHeight(part) + (i ? 18 : 0), 0);
            let sy = y;
            sideCards.forEach((part) => {
                const h = sideHeight(part);
                rect(pad, sy, sideW, h, soft, 18, line);
                rect(pad + cardPad, sy + 22, 6, 26, accent, 3);
                text(part.title.toUpperCase(), 22, 800, navy, pad + cardPad + 20, sy + 43, 'left', 2);
                para(part.value, 25, 500, ink, pad + cardPad, sy + 56, sideW - cardPad * 2, 34);
                sy += h + 18;
            });
            let ty = y;
            const dueRow = card.totals.find((row) => row.key === 'due');
            card.totals.forEach((row) => {
                if (row.key === 'due') return;
                if (row.key === 'total') {
                    rect(totalsX, ty + 4, totalsW, 2, line);
                    ty += 14;
                    text(row.label, 32, 800, navy, totalsX + 8, ty + 36);
                    text(row.value, 32, 800, navy, totalsX + totalsW - 8, ty + 36, 'right');
                    ty += 56;
                    return;
                }
                text(row.label, 26, 500, muted, totalsX + 8, ty + 30);
                text(row.value, 27, 600, row.key === 'paid' ? green : ink, totalsX + totalsW - 8, ty + 30, 'right');
                ty += 46;
            });
            if (dueRow) {
                ty += 8;
                rect(totalsX, ty, totalsW, 88, pay.key === 'paid' ? green : accent, 18);
                text(dueRow.label, 30, 800, '#ffffff', totalsX + 28, ty + 56);
                text(dueRow.value, 40, 800, '#ffffff', totalsX + totalsW - 28, ty + 59, 'right');
                ty += 88;
            }
            y += Math.max(sideH, ty - y) + 44;

            // 6. Footer: thank you.
            rect(pad, y, inner, 2, line);
            y += 26;
            text(card.labels.thanks, 31, 800, navy, W / 2, y + 31, 'center');
            y += 54;
            y += 36;
            rect(0, y, W, 14, navy);
            y += 14;

            const canvas = document.createElement('canvas');
            canvas.width = W;
            canvas.height = Math.ceil(y);
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            const path = (op) => {
                ctx.beginPath();
                if (op.r && ctx.roundRect) {
                    ctx.roundRect(op.x, op.y, op.w, op.h, op.r);
                } else {
                    ctx.rect(op.x, op.y, op.w, op.h);
                }
            };
            for (const op of ops) {
                if (op.t === 'img') {
                    ctx.save();
                    path(op);
                    ctx.clip();
                    if (op.border) {
                        ctx.fillStyle = soft2;
                        ctx.fillRect(op.x, op.y, op.w, op.h);
                    }
                    if (op.img) ctx.drawImage(op.img, op.x, op.y, op.w, op.h);
                    ctx.restore();
                    if (op.border) {
                        ctx.strokeStyle = op.border;
                        ctx.lineWidth = 2;
                        path(op);
                        ctx.stroke();
                    }
                    continue;
                }
                if (op.t === 'rect') {
                    path(op);
                    ctx.fillStyle = op.color;
                    ctx.fill();
                    if (op.stroke) {
                        ctx.strokeStyle = op.stroke;
                        ctx.lineWidth = 2;
                        ctx.stroke();
                    }
                    continue;
                }
                ctx.font = font(op.size, op.weight);
                ctx.fillStyle = op.color;
                ctx.textAlign = op.align;
                if ('letterSpacing' in ctx) ctx.letterSpacing = op.spacing + 'px';
                ctx.fillText(op.value, op.x, op.y);
            }
            return canvas;
        },
    }"
    class="space-y-4"
>
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
        <img x-show="previewUrl" :src="previewUrl" alt="{{ __('Order summary card') }}" class="block w-full">
        <p x-show="! previewUrl" class="p-6 text-center text-sm text-gray-500">{{ __('Creating the image…') }}</p>
    </div>

    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <button type="button" x-on:click="send('whatsapp')"
            class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#25D366] px-3 py-2.5 text-sm font-semibold text-white hover:opacity-90">
            {{ __('WhatsApp') }}
        </button>
        <button type="button" x-on:click="send('wechat')"
            class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#07C160] px-3 py-2.5 text-sm font-semibold text-white hover:opacity-90">
            {{ __('WeChat') }}
        </button>
        <button type="button" x-on:click="send('messenger')"
            class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#0866FF] px-3 py-2.5 text-sm font-semibold text-white hover:opacity-90">
            {{ __('Messenger') }}
        </button>
        <button type="button" x-on:click="send('telegram')"
            class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#229ED9] px-3 py-2.5 text-sm font-semibold text-white hover:opacity-90">
            {{ __('Telegram') }}
        </button>
    </div>

    <div class="flex flex-wrap gap-2">
        <button type="button" x-on:click="download()"
            class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium hover:bg-gray-50 dark:border-white/15 dark:hover:bg-white/5">
            {{ __('Download image') }}
        </button>
        <button type="button" x-on:click="copyText()"
            class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium hover:bg-gray-50 dark:border-white/15 dark:hover:bg-white/5">
            {{ __('Copy text') }}
        </button>
    </div>

    <p x-show="notice" x-transition class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300" x-text="notice"></p>
</div>
