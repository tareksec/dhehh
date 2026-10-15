<?php

namespace App\Services\Crm;

use App\Models\ChatOrderLink;
use App\Models\CompanyFaq;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ShippingFeeService;
use Illuminate\Validation\ValidationException;

/** Customer-visible facts are rendered from records, never from model prose. */
class SalesReplyRenderer
{
    public const PROMPTS = [
        'greeting' => ['কীভাবে সাহায্য করতে পারি?', 'How can I help you?'],
        'product' => ['কোন পণ্যটি খুঁজছেন?', 'Which product are you looking for?'],
        'variant' => ['কোন সাইজ বা কালারটি চান?', 'Which size or colour would you like?'],
        'quantity' => ['কতটি নিতে চান?', 'How many would you like?'],
        'budget' => ['আপনার বাজেট কত?', 'What is your budget?'],
        'delivery_area' => ['কোন এলাকায় ডেলিভারি নিতে চান?', 'Which area should we deliver to?'],
        'purchase_timeframe' => ['কখন নিতে চান?', 'When would you like to buy?'],
        'thanks' => ['ধন্যবাদ।', 'Thank you.'],
        'wellbeing' => ['আপনাকে সাহায্য করতে প্রস্তুত আছি।', 'Ready to help you.'],
        'identity' => ['আমি এই দোকানের AI সহকারী।', 'I am this store’s AI assistant.'],
    ];

    private const BANGLISH = [
        'greeting' => 'Kibhabe shahajjo korte pari?',
        'product' => 'Kon product ta khujchen?',
        'variant' => 'Kon size ba color ta chan?',
        'quantity' => 'Koyta nite chan?',
        'budget' => 'Apnar budget koto?',
        'delivery_area' => 'Kon elakay delivery nite chan?',
        'purchase_timeframe' => 'Kobe nite chan?',
        'thanks' => 'Dhonnobad.',
        'wellbeing' => 'Apnake shahajjo korte prostut achi.',
        'identity' => 'Ami ei dokaner AI assistant.',
    ];

    /**
     * $allowFollowUpPrompt opts into combining a verified answer with one
     * follow-up clarification prompt (proactive sales mode); off by default,
     * a prompt_key only renders when it is the sole thing being said.
     *
     * $style: 'rich' => true puts each product fact on its own labelled line
     * (price, options, stock, delivery); 'address_style' => sir_maam |
     * bhaiya_apu opens a Bangla reply with "জ্বি স্যার," etc. when the model
     * set input['salutation'] to sir or maam.
     */
    public function render(Conversation $conversation, array $input, array $trace, bool $allowFollowUpPrompt = false, array $style = []): string
    {
        $rich = (bool) ($style['rich'] ?? false);
        $language = $input['language'] ?? $this->detectLanguage($conversation);
        if (! in_array($language, ['bn', 'banglish', 'en'], true)) {
            throw ValidationException::withMessages(['language' => 'Unsupported reply language.']);
        }
        $bn = $language === 'bn';
        $banglish = $language === 'banglish';
        $detail = $input['product_detail'] ?? 'price';
        $allowed = [];
        foreach ($trace as $tool) {
            foreach ($tool['result']['reply_options'] ?? [] as $key) {
                $allowed[] = $key;
            }
        }
        $parts = [];
        foreach (array_slice((array) ($input['reply_keys'] ?? []), 0, 8) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw ValidationException::withMessages(['reply_keys' => 'Reply references facts that were not looked up.']);
            }
            [$type, $id] = array_pad(explode(':', $key, 2), 2, null);
            if ($type === 'product') {
                $product = Product::query()->where('company_id', $conversation->company_id)->where('is_active', true)->findOrFail($id);
                if ($product->has_variants && $rich) {
                    $parts[] = $this->richVariantProduct($product, $language, $detail);
                } elseif ($product->has_variants) {
                    $parts[] = $product->name.($bn ? ' — কোন সাইজ বা কালারটি চান?' : ($banglish ? ' — kon size ba color ta chan?' : ' — which size or colour would you like?'));
                } else {
                    $parts[] = $this->line($rich, $product->name, (float) $product->selling_price, $product->status === 'available' ? (int) $product->stock : 0, $language, $detail);
                }
            } elseif ($type === 'variant') {
                $variant = ProductVariant::query()->where('company_id', $conversation->company_id)->where('is_active', true)->findOrFail($id);
                abort_unless($variant->product?->is_active, 422);
                $parts[] = $this->line($rich, $variant->product->name.' / '.$variant->label(), $variant->effectiveSalePrice(), $variant->product->status === 'available' ? (int) $variant->stock : 0, $language, $detail);
            } elseif ($type === 'faq') {
                $parts[] = CompanyFaq::query()->where('company_id', $conversation->company_id)->where('is_active', true)->findOrFail($id)->answer;
            } elseif ($type === 'link') {
                $link = ChatOrderLink::query()->where('company_id', $conversation->company_id)->where('conversation_id', $conversation->getKey())->findOrFail($id);
                abort_unless($link->isUsable(), 422);
                $parts[] = ($rich ? '🛒 ' : '').($bn ? 'অর্ডার করতে: ' : ($banglish ? 'Order korte: ' : 'Order here: ')).$link->publicUrl();
            } elseif ($type === 'delivery') {
                $fees = (array) (app(ShippingFeeService::class)->defaultCourierProvider($conversation->company)?->settings['delivery_fees'] ?? []);
                if ($rich && $fees !== []) {
                    $parts[] = '🚚 '.($bn ? 'ডেলিভারি চার্জ:' : 'Delivery charge:')."\n"
                        .collect($fees)->map(fn ($fee, $zone): string => '• '.$zone.': '.$this->money((float) $fee, $bn, $banglish))->implode("\n");
                } else {
                    foreach ($fees as $zone => $fee) {
                        $parts[] = $zone.': '.($bn ? 'ডেলিভারি ' : 'delivery ').$this->money((float) $fee, $bn, $banglish);
                    }
                }
            } elseif ($type === 'related') {
                $product = Product::query()->where('company_id', $conversation->company_id)->where('is_active', true)->findOrFail($id);
                $framing = $bn ? 'সাথে এটাও দেখতে পারেন: ' : ($banglish ? 'Sathe eta o dekhte paren: ' : 'You might also like: ');
                $parts[] = $framing.$this->line($rich, $product->name, (float) $product->selling_price, $product->status === 'available' ? (int) $product->stock : 0, $language, $detail);
            }
        }
        $prompt = $input['prompt_key'] ?? null;
        if ($prompt !== null && isset(self::PROMPTS[$prompt]) && ($parts === [] || $allowFollowUpPrompt)) {
            $parts[] = $banglish ? self::BANGLISH[$prompt] : self::PROMPTS[$prompt][$bn ? 0 : 1];
        }
        if ($parts === []) {
            throw ValidationException::withMessages(['reply_keys' => 'Select verified reply keys or a supported clarification prompt.']);
        }
        $parts = array_values(array_unique($parts));
        if (($opening = $this->salutation($language, (string) ($style['address_style'] ?? 'none'), $input['salutation'] ?? null)) !== null) {
            $parts[0] = $opening.$parts[0];
        }

        return implode("\n", $parts);
    }

    /** "জ্বি স্যার, " and the like: only for Bangla/Banglish replies, only when the model knows. */
    private function salutation(string $language, string $addressStyle, mixed $salutation): ?string
    {
        if ($language === 'en' || ! in_array($salutation, ['sir', 'maam'], true)) {
            return null;
        }
        $title = match ($addressStyle) {
            'sir_maam' => $salutation === 'sir' ? ['স্যার', 'sir'] : ['ম্যাম', 'madam'],
            'bhaiya_apu' => $salutation === 'sir' ? ['ভাইয়া', 'bhaiya'] : ['আপু', 'apu'],
            default => null,
        };

        return $title === null ? null : ($language === 'bn' ? 'জ্বি '.$title[0].', ' : 'Ji '.$title[1].', ');
    }

    private function line(bool $rich, string $name, float $price, int $stock, string $language, string $detail): string
    {
        return $rich ? $this->richProductLine($name, $price, $stock, $language, $detail) : $this->productLine($name, $price, $stock, $language, $detail);
    }

    /** Name on its own line, then one labelled line per fact asked about. Stock is never a count. */
    private function richProductLine(string $name, float $price, int $stock, string $language, string $detail): string
    {
        $bn = $language === 'bn';
        $banglish = $language === 'banglish';
        $lines = [$name];
        if ($detail !== 'stock') {
            $lines[] = '💰 '.($bn ? 'দাম: ' : ($banglish ? 'Dam: ' : 'Price: ')).$this->money($price, $bn, $banglish);
        }
        if ($detail !== 'price') {
            $lines[] = $stock > 0
                ? '✅ '.($bn ? 'স্টকে আছে' : ($banglish ? 'Stock e ache' : 'In stock'))
                : '❌ '.($bn ? 'এখন স্টকে নেই' : ($banglish ? 'Ekhon stock e nei' : 'Currently out of stock'));
        }

        return implode("\n", $lines);
    }

    /** A product with sizes/colours: its price (or price range), the options in stock, then which one. */
    private function richVariantProduct(Product $product, string $language, string $detail): string
    {
        $bn = $language === 'bn';
        $banglish = $language === 'banglish';
        $variants = $product->activeVariants()->get();
        $inStock = $product->status === 'available' ? $variants->filter(fn (ProductVariant $variant): bool => (int) $variant->stock > 0) : collect();
        $lines = [$product->name];
        if ($detail !== 'stock' && $variants->isNotEmpty()) {
            $min = (float) $variants->min(fn (ProductVariant $variant): float => $variant->effectiveSalePrice());
            $max = (float) $variants->max(fn (ProductVariant $variant): float => $variant->effectiveSalePrice());
            $lines[] = '💰 '.($bn ? 'দাম: ' : ($banglish ? 'Dam: ' : 'Price: ')).$this->money($min, $bn, $banglish)
                .($max > $min ? ' – '.$this->money($max, $bn, $banglish) : '');
        }
        if ($inStock->isNotEmpty()) {
            // One option (e.g. size only): "Size: M, XL" instead of "Size: M, Size: XL".
            $optionKeys = $inStock->flatMap(fn (ProductVariant $variant): array => array_keys((array) $variant->options))->unique();
            $options = $optionKeys->count() === 1
                ? $optionKeys->first().': '.$inStock->map(fn (ProductVariant $variant): string => (string) data_get($variant->options, $optionKeys->first()))->implode(', ')
                : $inStock->map(fn (ProductVariant $variant): string => $variant->label())->implode(', ');
            $lines[] = '📏 '.($bn ? 'স্টকে আছে: ' : ($banglish ? 'Stock e ache: ' : 'In stock: ')).$options;
            $lines[] = $bn ? 'কোনটি নেবেন?' : ($banglish ? 'Konta niben?' : 'Which one would you like?');
        } else {
            $lines[] = '❌ '.($bn ? 'এখন স্টকে নেই' : ($banglish ? 'Ekhon stock e nei' : 'Currently out of stock'));
        }

        return implode("\n", $lines);
    }

    /** The short message sent when the AI hands a chat to the team (never promises a time). */
    public function handoverNotice(Conversation $conversation): string
    {
        return match ($this->detectLanguage($conversation)) {
            'en' => 'A member of our team is looking into this now, please wait a moment.',
            'banglish' => 'Bishoyta amader ekjon protinidhi ekhoni dekhchen, ektu opekkha korun.',
            default => 'বিষয়টা আমাদের একজন প্রতিনিধি এখনই দেখছেন, একটু অপেক্ষা করুন।',
        };
    }

    private function money(float $price, bool $bn, bool $banglish = false): string
    {
        $amount = rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.');

        return $bn ? strtr($amount, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']).' টাকা' : ($banglish ? $amount.' taka' : 'BDT '.$amount);
    }

    private function productLine(string $name, float $price, int $stock, string $language, string $detail): string
    {
        $bn = $language === 'bn';
        $banglish = $language === 'banglish';
        $availability = $stock > 0 ? ($bn ? 'স্টকে আছে।' : ($banglish ? 'stock e ache.' : 'In stock.')) : ($bn ? 'এখন স্টকে নেই।' : ($banglish ? 'Ekhon stock e nei.' : 'Currently out of stock.'));

        return $name.' — '.match ($detail) {
            'stock' => $availability,
            'both' => $this->money($price, $bn, $banglish).', '.$availability,
            default => $this->money($price, $bn, $banglish),
        };
    }

    private function detectLanguage(Conversation $conversation): string
    {
        return $this->languageOf($conversation->messages()->where('direction', 'incoming')->latest('id')->limit(5)->pluck('body'));
    }

    /** Language of the newest of these customer messages that shows one; Bangla when none does. */
    public function languageOf(iterable $bodies): string
    {
        foreach ($bodies as $body) {
            if (preg_match('/[\x{0980}-\x{09FF}]/u', (string) $body)) {
                return 'bn';
            }
            if (preg_match('/\b(tumi|apni|kmn|kemon|aso|achen|achi|koto|koyta|dam|daam|ache|ase|naki|nibo|chai|chan|dhonnobad|pathaite|parben)\b/i', (string) $body)) {
                return 'banglish';
            }
            if (preg_match('/\b(price|how|what|available|please|thank|which|want)\b/i', (string) $body)) {
                return 'en';
            }
        }

        return 'bn';
    }
}
