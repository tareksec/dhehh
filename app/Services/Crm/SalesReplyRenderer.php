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
     */
    public function render(Conversation $conversation, array $input, array $trace, bool $allowFollowUpPrompt = false): string
    {
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
                if ($product->has_variants) {
                    $parts[] = $product->name.($bn ? ' — কোন সাইজ বা কালারটি চান?' : ($banglish ? ' — kon size ba color ta chan?' : ' — which size or colour would you like?'));
                } else {
                    $parts[] = $this->productLine($product->name, (float) $product->selling_price, $product->status === 'available' ? (int) $product->stock : 0, $language, $detail);
                }
            } elseif ($type === 'variant') {
                $variant = ProductVariant::query()->where('company_id', $conversation->company_id)->where('is_active', true)->findOrFail($id);
                abort_unless($variant->product?->is_active, 422);
                $parts[] = $this->productLine($variant->product->name.' / '.$variant->label(), $variant->effectiveSalePrice(), $variant->product->status === 'available' ? (int) $variant->stock : 0, $language, $detail);
            } elseif ($type === 'faq') {
                $parts[] = CompanyFaq::query()->where('company_id', $conversation->company_id)->where('is_active', true)->findOrFail($id)->answer;
            } elseif ($type === 'link') {
                $link = ChatOrderLink::query()->where('company_id', $conversation->company_id)->where('conversation_id', $conversation->getKey())->findOrFail($id);
                abort_unless($link->isUsable(), 422);
                $parts[] = ($bn ? 'অর্ডার করতে: ' : ($banglish ? 'Order korte: ' : 'Order here: ')).$link->publicUrl();
            } elseif ($type === 'delivery') {
                $fees = (array) (app(ShippingFeeService::class)->defaultCourierProvider($conversation->company)?->settings['delivery_fees'] ?? []);
                foreach ($fees as $zone => $fee) {
                    $parts[] = $zone.': '.($bn ? 'ডেলিভারি ' : 'delivery ').$this->money((float) $fee, $bn, $banglish);
                }
            } elseif ($type === 'related') {
                $product = Product::query()->where('company_id', $conversation->company_id)->where('is_active', true)->findOrFail($id);
                $framing = $bn ? 'সাথে এটাও দেখতে পারেন: ' : ($banglish ? 'Sathe eta o dekhte paren: ' : 'You might also like: ');
                $parts[] = $framing.$this->productLine($product->name, (float) $product->selling_price, $product->status === 'available' ? (int) $product->stock : 0, $language, $detail);
            }
        }
        $prompt = $input['prompt_key'] ?? null;
        if ($prompt !== null && isset(self::PROMPTS[$prompt]) && ($parts === [] || $allowFollowUpPrompt)) {
            $parts[] = $banglish ? self::BANGLISH[$prompt] : self::PROMPTS[$prompt][$bn ? 0 : 1];
        }
        if ($parts === []) {
            throw ValidationException::withMessages(['reply_keys' => 'Select verified reply keys or a supported clarification prompt.']);
        }

        return implode("\n", array_unique($parts));
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
        foreach ($conversation->messages()->where('direction', 'incoming')->latest('id')->limit(5)->pluck('body') as $body) {
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
