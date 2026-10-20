<?php

namespace App\Services\Crm;

use App\Models\ChatOrderLink;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Meta\MetaGraphService;
use App\Support\CompanyMedia;
use Illuminate\Support\Str;

/**
 * Product cards for Messenger: a carousel with photo, price and a button,
 * built from the same facts the written answer cites. The AI chooses nothing
 * here: the cards are the products behind the reply keys that SalesReplyRenderer
 * already accepted, with the current price and stock read from the records.
 */
class ProductCardService
{
    /** Meta allows 10 cards in one carousel. */
    public const MAX_CARDS = 10;

    public function __construct(
        protected MetaGraphService $meta,
        protected SalesReplyRenderer $renderer,
    ) {}

    /**
     * @return list<array<string, mixed>> Messenger "generic template" elements
     */
    public function elements(Conversation $conversation, array $input): array
    {
        $companyId = (int) $conversation->company_id;
        $language = $input['language'] ?? $this->renderer->languageOf(
            $conversation->messages()->where('direction', 'incoming')->latest('id')->limit(5)->pluck('body'),
        );
        $language = in_array($language, ['bn', 'banglish', 'en'], true) ? $language : 'bn';

        $products = [];
        $orderUrl = null;
        foreach (array_slice((array) ($input['reply_keys'] ?? []), 0, 8) as $key) {
            if (! is_string($key)) {
                continue;
            }
            [$type, $id] = array_pad(explode(':', $key, 2), 2, null);
            $product = match ($type) {
                'product', 'related' => Product::query()->where('company_id', $companyId)->where('is_active', true)->find($id),
                'variant' => ProductVariant::query()->where('company_id', $companyId)->where('is_active', true)->find($id)?->product,
                default => null,
            };
            if ($product && (int) $product->company_id === $companyId && $product->is_active) {
                $products[$product->getKey()] ??= $product;
            }
            if ($type === 'link') {
                $link = ChatOrderLink::query()->where('company_id', $companyId)->where('conversation_id', $conversation->getKey())->find($id);
                $orderUrl = $link?->isUsable() ? $link->publicUrl() : $orderUrl;
            }
        }

        $elements = [];
        foreach (array_slice(array_values($products), 0, self::MAX_CARDS) as $product) {
            $image = $this->meta->publicMediaUrl(CompanyMedia::publicUrl($product->image, $product));
            if ($image === null) {
                continue; // a card without a photo is not worth a carousel
            }
            $elements[] = $this->element($conversation, $product, $image, $orderUrl, $language);
        }

        return $elements;
    }

    /** The one-line text kept in the chat history for a sent carousel. */
    public function summary(array $elements): string
    {
        return '🛍 '.collect($elements)->pluck('title')->implode(', ');
    }

    protected function element(Conversation $conversation, Product $product, string $image, ?string $orderUrl, string $language): array
    {
        $url = $orderUrl ?? $this->productPageUrl($conversation, $product);
        $element = [
            'title' => Str::limit($product->name, 77, '...'),
            'subtitle' => Str::limit($this->subtitle($product, $language), 77, '...'),
            'image_url' => $image,
        ];
        if ($url !== null) {
            $element['default_action'] = ['type' => 'web_url', 'url' => $url];
            $element['buttons'] = [[
                'type' => 'web_url',
                'url' => $url,
                'title' => $orderUrl !== null
                    ? ($language === 'bn' ? 'অর্ডার করুন' : ($language === 'banglish' ? 'Order korun' : 'Order now'))
                    : ($language === 'bn' ? 'দেখুন' : ($language === 'banglish' ? 'Dekhun' : 'View')),
            ]];
        }

        return $element;
    }

    /** "BDT 420 · In stock", from the current records. */
    protected function subtitle(Product $product, string $language): string
    {
        if ($product->has_variants) {
            $variants = $product->activeVariants()->get();
            $prices = $variants->map(fn (ProductVariant $variant): float => $variant->effectiveSalePrice());
            $inStock = $product->status === 'available' && $variants->contains(fn (ProductVariant $variant): bool => (int) $variant->stock > 0);
            $price = $prices->isEmpty() ? (float) $product->selling_price : (float) $prices->min();
            $range = $prices->isNotEmpty() && (float) $prices->max() > $price ? ' – '.$this->renderer->formatMoney((float) $prices->max(), $language) : '';
        } else {
            $price = (float) $product->selling_price;
            $range = '';
            $inStock = $product->status === 'available' && (int) $product->stock > 0;
        }

        $availability = $inStock
            ? ($language === 'bn' ? 'স্টকে আছে' : ($language === 'banglish' ? 'Stock e ache' : 'In stock'))
            : ($language === 'bn' ? 'এখন স্টকে নেই' : ($language === 'banglish' ? 'Ekhon stock e nei' : 'Out of stock'));

        return $this->renderer->formatMoney($price, $language).$range.' · '.$availability;
    }

    /** The product page on the company's own storefront domain, when it has one. */
    protected function productPageUrl(Conversation $conversation, Product $product): ?string
    {
        $domain = trim((string) $conversation->company?->domain);

        return $domain !== '' && filled($product->slug)
            ? 'https://'.$domain.route('storefront.products.show', $product->slug, false)
            : null;
    }
}
