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
            return Promise.all(this.card.items.map((item, index) => new Promise((resolve) => {
                if (! item.image) return resolve();
                const img = new Image();
                img.onload = () => { this.images[index] = img; resolve(); };
                img.onerror = () => resolve();
                img.src = item.image;
            })));
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
            const W = 1080, pad = 64, inner = W - pad * 2;
            const navy = '#0f2a43', accent = '#ff6a00', ink = '#0f172a', muted = '#64748b', line = '#e2e8f0', soft = '#f1f5f9';
            const font = (size, weight) => (weight || 400) + ' ' + size + 'px system-ui, -apple-system, Roboto, \'Segoe UI\', \'Noto Sans Bengali\', sans-serif';
            const ctx0 = document.createElement('canvas').getContext('2d');
            const ops = [];
            const put = (value, size, weight, color, x, y, align) => ops.push({ t: 'text', value, size, weight, color, x, y, align: align || 'left' });
            const block = (value, size, weight, color, x, y, maxWidth, gap) => {
                ctx0.font = font(size, weight);
                const lines = this.wrap(ctx0, value, maxWidth);
                lines.forEach((l, i) => put(l, size, weight, color, x, y + size + i * (size + (gap || 10)), 'left'));
                return lines.length * (size + (gap || 10));
            };

            // Header band: company name + title.
            const headerH = 200;
            ops.push({ t: 'rect', x: 0, y: 0, w: W, h: headerH, color: navy });
            ops.push({ t: 'rect', x: 0, y: headerH, w: W, h: 10, color: accent });
            ctx0.font = font(52, 800);
            put(this.wrap(ctx0, this.card.company, inner)[0], 52, 800, '#ffffff', pad, 104);
            put(this.card.title, 30, 500, '#cbd5e1', pad, 156);

            // Details: label / value rows.
            let y = headerH + 10 + 44;
            const labelW = 250;
            for (const row of this.card.meta) {
                put(row.label, 28, 600, muted, pad, y + 28);
                y += block(row.value, 30, 600, ink, pad + labelW, y - 1, inner - labelW, 10) + 14;
            }

            // Items table.
            if (this.card.items.length) {
                y += 18;
                ops.push({ t: 'rect', x: pad, y: y, w: inner, h: 62, color: soft, r: 12 });
                put(this.card.labels.items, 26, 700, muted, pad + 24, y + 41);
                put(this.card.labels.quantity, 26, 700, muted, W - pad - 280, y + 41, 'center');
                put(this.card.labels.amount, 26, 700, muted, W - pad - 24, y + 41, 'right');
                y += 62 + 18;
                // Rows with a product picture: the customer sees the very
                // product they ordered. Items without a picture get a plain box.
                const thumb = 104;
                const hasThumbs = this.card.items.some((item) => item.image);
                const textX = pad + 24 + (hasThumbs ? thumb + 24 : 0);
                const textW = inner - 400 - (textX - (pad + 24));
                this.card.items.forEach((item, i) => {
                    const label = (i + 1) + '. ' + item.name;
                    ctx0.font = font(30, 500);
                    const textH = this.wrap(ctx0, label, textW).length * 40;
                    const rowH = hasThumbs ? Math.max(textH, thumb) : textH;
                    if (hasThumbs) {
                        ops.push({ t: 'img', img: this.images[i] || null, x: pad + 24, y: y + (rowH - thumb) / 2, size: thumb });
                    }
                    block(label, 30, 500, ink, textX, y + (rowH - textH) / 2, textW, 10);
                    put(String(item.quantity), 30, 600, ink, W - pad - 280, y + rowH / 2 + 10, 'center');
                    put(item.amount, 30, 700, ink, W - pad - 24, y + rowH / 2 + 10, 'right');
                    y += rowH + 14;
                    ops.push({ t: 'rect', x: pad, y: y, w: inner, h: 2, color: line });
                    y += 16;
                });
            }

            // Totals box.
            y += 18;
            const rows = this.card.totals;
            const boxH = rows.reduce((h, r) => h + (r.strong ? 60 : 50), 0) + 36;
            ops.push({ t: 'rect', x: pad, y: y, w: inner, h: boxH, color: soft, r: 18 });
            let ty = y + 18;
            for (const r of rows) {
                const size = r.strong ? 36 : 29;
                const color = r.key === 'due' ? accent : ink;
                put(r.label, size, r.strong ? 800 : 500, r.strong ? ink : muted, pad + 32, ty + size + 6);
                put(r.value, size, r.strong ? 800 : 600, color, W - pad - 32, ty + size + 6, 'right');
                ty += r.strong ? 60 : 50;
            }
            y += boxH + 44;

            // Footer.
            put(this.card.labels.thanks, 30, 700, navy, W / 2, y + 30, 'center');
            y += 46;
            if (this.card.company_phone) {
                put(this.card.company + ' · ' + this.card.company_phone, 26, 500, muted, W / 2, y + 26, 'center');
                y += 40;
            }
            y += pad - 10;

            const canvas = document.createElement('canvas');
            canvas.width = W;
            canvas.height = Math.ceil(y);
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            for (const op of ops) {
                if (op.t === 'img') {
                    ctx.save();
                    ctx.beginPath();
                    if (ctx.roundRect) {
                        ctx.roundRect(op.x, op.y, op.size, op.size, 12);
                    } else {
                        ctx.rect(op.x, op.y, op.size, op.size);
                    }
                    ctx.clip();
                    ctx.fillStyle = soft;
                    ctx.fillRect(op.x, op.y, op.size, op.size);
                    if (op.img) ctx.drawImage(op.img, op.x, op.y, op.size, op.size);
                    ctx.restore();
                    continue;
                }
                if (op.t === 'rect') {
                    ctx.fillStyle = op.color;
                    ctx.beginPath();
                    if (op.r && ctx.roundRect) {
                        ctx.roundRect(op.x, op.y, op.w, op.h, op.r);
                    } else {
                        ctx.rect(op.x, op.y, op.w, op.h);
                    }
                    ctx.fill();
                    continue;
                }
                ctx.font = font(op.size, op.weight);
                ctx.fillStyle = op.color;
                ctx.textAlign = op.align;
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
