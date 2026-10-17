{{--
    The reseller portal's product details slide-over (Products::detailsFor()).
    Shows the product's own facts plus the signed-in reseller's own cost,
    price and commission only; the company's purchase cost and wholesale
    tiers are never printed here.
--}}
@php
    use App\Support\ClipboardCopy;
    use App\Support\CompanyMedia;
    use App\Support\MoneyFormatter;
    use Filament\Forms\Components\RichEditor\RichContentRenderer;

    $url = fn (?string $path): ?string => CompanyMedia::filamentPublicUrl($path, $product);
    $isHtml = str_contains((string) $product->description, '<');
    $plainDescription = trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>'], "\n", (string) $product->description)), ENT_QUOTES));
    $videoUrl = $url($product->video_path);
    $money = fn ($amount): string => MoneyFormatter::currency($amount, $currency);
@endphp

<div class="space-y-6" data-reseller-product-details>
    @if ($images !== [])
        <div x-data="{ main: @js($url($images[0])) }" class="space-y-3">
            <div class="flex aspect-square max-h-96 w-full items-center justify-center overflow-hidden rounded-lg bg-gray-50 p-2 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <img :src="main" alt="{{ $product->name }}" class="h-full w-full object-contain">
            </div>
            @if (count($images) > 1)
                <div class="flex flex-wrap gap-2">
                    @foreach ($images as $path)
                        <button
                            type="button"
                            x-on:click="main = @js($url($path))"
                            class="h-16 w-16 overflow-hidden rounded-md bg-gray-50 ring-1 ring-gray-950/10 hover:ring-primary-500 dark:bg-white/5 dark:ring-white/10"
                        >
                            <img src="{{ $url($path) }}" alt="" loading="lazy" class="h-full w-full object-cover">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if ($videoUrl)
        <video src="{{ $videoUrl }}" controls preload="metadata" class="w-full rounded-lg bg-black"></video>
    @endif

    <div>
        <h3 class="mb-2 text-sm font-semibold">{{ __('Your price and profit') }}</h3>
        <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div>
                <div class="text-gray-500 dark:text-gray-400">{{ __('Your cost') }}</div>
                <div class="font-medium">{{ $cost === null ? __('No wholesale rate') : $money($cost) }}</div>
            </div>
            <div>
                <div class="text-gray-500 dark:text-gray-400">{{ __('Suggested price') }}</div>
                <div class="font-medium">{{ $money($suggested) }}</div>
            </div>
            <div>
                <div class="text-gray-500 dark:text-gray-400">{{ __('Your price') }}</div>
                <div class="font-medium">{{ $yourPrice === null ? '—' : $money($yourPrice) }}</div>
            </div>
            <div>
                <div class="text-gray-500 dark:text-gray-400">{{ __('Profit per unit') }}</div>
                <div class="font-medium">{{ $commission === null ? '—' : $money($commission) }}</div>
            </div>
        </div>
        @unless ($inStore)
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Add this product to your store to set your price.') }}</p>
        @endunless
    </div>

    <div>
        <h3 class="mb-2 text-sm font-semibold">{{ __('Product details') }}</h3>
        <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div>
                <div class="text-gray-500 dark:text-gray-400">{{ __('SKU') }}</div>
                <div class="font-medium">{{ $product->sku ?: '—' }}</div>
            </div>
            <div>
                <div class="text-gray-500 dark:text-gray-400">{{ __('Brand') }}</div>
                <div class="font-medium">{{ $product->brand ?: '—' }}</div>
            </div>
            <div>
                <div class="text-gray-500 dark:text-gray-400">{{ __('Category') }}</div>
                <div class="font-medium">{{ $product->category?->name ?? '—' }}</div>
            </div>
            <div>
                <div class="text-gray-500 dark:text-gray-400">{{ __('Unit') }}</div>
                <div class="font-medium">{{ $product->unit ?: '—' }}</div>
            </div>
            <div>
                <div class="text-gray-500 dark:text-gray-400">{{ __('Weight') }}</div>
                <div class="font-medium">{{ $product->weight_kg !== null && (float) $product->weight_kg > 0 ? rtrim(rtrim(number_format((float) $product->weight_kg, 3), '0'), '.').' kg' : '—' }}</div>
            </div>
            <div>
                <div class="text-gray-500 dark:text-gray-400">{{ __('Stock') }}</div>
                <div class="font-medium">
                    {{ (int) $product->stock }}
                    @if ($product->is_preorder)
                        <span class="text-gray-500 dark:text-gray-400">({{ __('Pre-order') }})</span>
                    @endif
                </div>
            </div>
            <div>
                <div class="text-gray-500 dark:text-gray-400">{{ __('Minimum order quantity') }}</div>
                <div class="font-medium">{{ $product->effectiveMoq() }}</div>
            </div>
        </div>
    </div>

    <div>
        <div class="mb-2 flex items-center justify-between gap-3">
            <h3 class="text-sm font-semibold">{{ __('Description') }}</h3>
            @if ($plainDescription !== '')
                <x-filament::button
                    size="xs"
                    color="gray"
                    icon="heroicon-m-clipboard-document"
                    x-on:click="{{ ClipboardCopy::alpineHandler($plainDescription, __('Copied to clipboard')) }}"
                >
                    {{ __('Copy description') }}
                </x-filament::button>
            @endif
        </div>
        @if (blank($product->description))
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No description yet.') }}</p>
        @elseif ($isHtml)
            <div class="prose prose-sm max-w-none dark:prose-invert">{{ RichContentRenderer::make($product->description) }}</div>
        @else
            <p class="whitespace-pre-line text-sm">{{ $product->description }}</p>
        @endif
    </div>

    @if ($activeVariants->isNotEmpty())
        <div>
            <h3 class="mb-2 text-sm font-semibold">{{ __('Variations') }}</h3>
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="text-gray-500 dark:text-gray-400">
                        <th class="pb-1">{{ __('Variation') }}</th>
                        <th class="pb-1">{{ __('SKU') }}</th>
                        <th class="pb-1 text-right">{{ __('Suggested price') }}</th>
                        <th class="pb-1 text-right">{{ __('Your cost') }}</th>
                        <th class="pb-1 text-right">{{ __('Your price') }}</th>
                        <th class="pb-1 text-right">{{ __('Profit per unit') }}</th>
                        <th class="pb-1 text-right">{{ __('Stock') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($variationRows as $row)
                        <tr class="border-t border-gray-100 dark:border-white/10">
                            <td class="py-1.5">
                                <div class="flex items-center gap-2">
                                    @foreach (array_slice($row['variant']->images ?? [], 0, 2) as $path)
                                        <img src="{{ $url($path) }}" alt="" loading="lazy" class="h-10 w-10 rounded object-cover ring-1 ring-gray-950/10 dark:ring-white/10">
                                    @endforeach
                                    <span>{{ $row['variant']->label() ?: '—' }}</span>
                                </div>
                            </td>
                            <td class="py-1.5">{{ $row['variant']->sku ?: '—' }}</td>
                            <td class="py-1.5 text-right">{{ $row['suggested'] === null ? '—' : $money($row['suggested']) }}</td>
                            <td class="py-1.5 text-right">{{ $row['cost'] === null ? '—' : $money($row['cost']) }}</td>
                            <td class="py-1.5 text-right">
                                @if ($row['price'] !== null)
                                    {{ $money($row['price']) }}
                                @elseif ($inStore && $row['cost'] !== null)
                                    <span class="text-warning-600 dark:text-warning-400">{{ __('Set your price') }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="py-1.5 text-right">{{ $row['commission'] === null ? '—' : $money($row['commission']) }}</td>
                            <td class="py-1.5 text-right">{{ (int) $row['variant']->stock }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('The difference between the price you set and what you pay the store is your profit. Set your prices with Variation prices.') }}</p>
        </div>
    @endif

    <div class="flex flex-wrap gap-2">
        @if ($inStore)
            <x-filament::button
                tag="a"
                size="sm"
                color="gray"
                icon="heroicon-m-arrow-top-right-on-square"
                :href="$storeUrl"
                target="_blank"
                rel="noopener"
            >
                {{ __('View in my store') }}
            </x-filament::button>
        @endif
        @if ($images !== [])
            <x-filament::button
                tag="a"
                size="sm"
                color="gray"
                icon="heroicon-m-arrow-down-tray"
                :href="route('reseller.portal.products.photos', $product)"
                target="_blank"
            >
                {{ __('Download photos') }}
            </x-filament::button>
        @endif
    </div>
</div>
