<?php

namespace App\Services\Crm;

use App\Models\Company;
use App\Models\CompanyFaq;
use App\Models\ConversationChannel;
use App\Models\CourierProvider;
use App\Models\CrmAiRun;
use App\Models\KnowledgeDocument;
use App\Models\Product;
use App\Models\PushDevice;
use App\Models\User;

/**
 * The steps that make the AI inbox ready for real customers, each worked out
 * from the company's data (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.9). Nothing is
 * stored, so a step turns green by itself once the work is done.
 */
class AiSetupChecklistService
{
    /**
     * @return list<array{key:string,done:bool,detail:?string}>
     */
    public function steps(Company $company, ?User $user = null): array
    {
        $settings = app(AiSettingsService::class);
        $messaging = $settings->all($company, AiSettingsService::TOOL_MESSAGING);

        $products = Product::query()->where('company_id', $company->getKey())->where('is_active', true);
        $activeProducts = (clone $products)->count();
        $withPhoto = (clone $products)->whereNotNull('image')->where('image', '!=', '')->count();

        $deliveryFees = (array) (CourierProvider::defaultForCompany($company->getKey())?->settings['delivery_fees'] ?? []);

        $answers = CompanyFaq::query()->where('company_id', $company->getKey())->where('is_active', true)->count()
            + KnowledgeDocument::query()->where('company_id', $company->getKey())->where('is_active', true)->count();

        return [
            [
                'key' => 'channel',
                'done' => ConversationChannel::query()->where('company_id', $company->getKey())->where('is_active', true)->whereNotNull('access_token')->exists(),
                'detail' => null,
            ],
            [
                'key' => 'ai',
                'done' => $settings->enabled($company, AiSettingsService::TOOL_MESSAGING),
                'detail' => null,
            ],
            [
                'key' => 'about',
                'done' => filled(trim((string) ($messaging['business_about'] ?? ''))),
                'detail' => null,
            ],
            [
                'key' => 'products',
                'done' => $activeProducts > 0 && $withPhoto === $activeProducts,
                'detail' => $activeProducts > 0 ? __(':with of :total products have a photo', ['with' => $withPhoto, 'total' => $activeProducts]) : null,
            ],
            [
                'key' => 'delivery',
                'done' => $deliveryFees !== [],
                'detail' => null,
            ],
            [
                'key' => 'answers',
                'done' => $answers > 0,
                'detail' => $answers > 0 ? __(':count saved answers and documents', ['count' => $answers]) : null,
            ],
            [
                'key' => 'alerts',
                'done' => $user !== null && PushDevice::query()->where('user_id', $user->getKey())->where('is_active', true)->exists(),
                'detail' => null,
            ],
            [
                'key' => 'test',
                'done' => CrmAiRun::query()->where('company_id', $company->getKey())->where('is_test', true)->exists(),
                'detail' => null,
            ],
            [
                'key' => 'live',
                'done' => $settings->enabled($company, AiSettingsService::TOOL_MESSAGING) && (bool) $messaging['ai_live'],
                'detail' => null,
            ],
        ];
    }
}
