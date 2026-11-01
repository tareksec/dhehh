<x-filament-panels::page>
    @php
        $orders = $this->orders();
    @endphp

    <div
        class="space-y-6"
        x-data="{
            camera: false,
            stream: null,
            timer: null,
            last: '',
            async start() {
                if (!('BarcodeDetector' in window)) {
                    alert(@js(__('This browser cannot read barcodes with the camera. Use a barcode scanner or type the number.')));
                    return;
                }
                try {
                    this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                    this.camera = true;
                    await this.$nextTick();
                    this.$refs.video.srcObject = this.stream;
                    await this.$refs.video.play();
                    const detector = new BarcodeDetector({ formats: ['code_128', 'qr_code'] });
                    this.timer = setInterval(async () => {
                        const found = await detector.detect(this.$refs.video).catch(() => []);
                        if (found.length && found[0].rawValue !== this.last) {
                            this.last = found[0].rawValue;
                            $wire.set('code', this.last);
                            $wire.scan();
                        }
                    }, 400);
                } catch (error) {
                    this.stop();
                    alert(@js(__('The camera could not be opened.')));
                }
            },
            stop() {
                clearInterval(this.timer);
                if (this.stream) { this.stream.getTracks().forEach((track) => track.stop()); }
                this.stream = null;
                this.camera = false;
                this.last = '';
            },
        }"
        x-on:beforeunload.window="stop()"
    >
        <x-filament::section>
            <div class="flex flex-wrap items-end gap-3">
                <label class="block min-w-[14rem] flex-1 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('Invoice number') }}</span>
                    <input
                        type="text"
                        wire:model="code"
                        wire:keydown.enter="scan"
                        autofocus
                        autocomplete="off"
                        inputmode="text"
                        placeholder="{{ __('Scan or type, then press Enter') }}"
                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"
                    >
                </label>
                <x-filament::button type="button" wire:click="scan">{{ __('Add') }}</x-filament::button>
                <x-filament::button type="button" color="gray" x-show="!camera" x-on:click="start()">{{ __('Use camera') }}</x-filament::button>
                <x-filament::button type="button" color="gray" x-show="camera" x-cloak x-on:click="stop()">{{ __('Stop camera') }}</x-filament::button>
            </div>
            <div x-show="camera" x-cloak class="mt-4">
                <video x-ref="video" playsinline muted class="max-h-64 w-full rounded-lg bg-black object-contain"></video>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm font-medium">{{ __(':count parcel(s) scanned', ['count' => $orders->count()]) }}</p>
                @if ($orders->isNotEmpty())
                    <x-filament::button type="button" color="gray" size="sm" wire:click="clear">{{ __('Clear list') }}</x-filament::button>
                @endif
            </div>

            @forelse ($orders as $order)
                @php
                    $canMove = in_array($this->stage, $order->allowedForwardWorkflowStages(), true);
                @endphp
                <div class="flex items-center gap-3 py-2 {{ ! $loop->first ? 'border-t border-gray-200 dark:border-white/10' : '' }}" wire:key="scan-{{ $order->id }}">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold">{{ $order->order_number }}
                            <span class="font-normal text-gray-500 dark:text-gray-400">· {{ $order->customer?->name ?? $order->customer_name }}</span>
                        </p>
                        <p class="text-xs {{ $canMove ? 'text-gray-500 dark:text-gray-400' : 'text-danger-600 dark:text-danger-400' }}">
                            {{ __(\App\Models\Order::WORKFLOW_STAGES[$order->workflowStage()] ?? $order->workflowStage()) }}
                            @unless ($canMove)
                                · {{ __('cannot move to :stage from here', ['stage' => __(\App\Models\Order::WORKFLOW_STAGES[$this->stage])]) }}
                            @endunless
                        </p>
                    </div>
                    <x-filament::button type="button" color="gray" size="sm" wire:click="remove({{ $order->id }})">{{ __('Remove') }}</x-filament::button>
                </div>
            @empty
                <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing scanned yet.') }}</p>
            @endforelse

            @if ($orders->isNotEmpty())
                <div class="mt-4 flex flex-wrap items-end gap-3 border-t border-gray-200 pt-4 dark:border-white/10">
                    <label class="block text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('Move all to') }}</span>
                        <select wire:model.live="stage" class="mt-1 block rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                            @foreach ($this->stageOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <x-filament::button type="button" wire:click="move" wire:loading.attr="disabled" wire:target="move">{{ __('Move :count parcel(s)', ['count' => $orders->count()]) }}</x-filament::button>
                </div>
            @endif

            @if ($this->skipped !== [])
                <div class="mt-4 rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-200">
                    <p class="font-medium">{{ __(':count order(s) were skipped:', ['count' => count($this->skipped)]) }}</p>
                    <ul class="mt-1 list-disc pl-5">
                        @foreach ($this->skipped as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
