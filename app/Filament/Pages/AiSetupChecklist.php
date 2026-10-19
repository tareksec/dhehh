<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Crm;
use App\Filament\Resources\CompanyFaqs\CompanyFaqResource;
use App\Filament\Resources\CourierProviders\CourierProviderResource;
use App\Filament\Resources\KnowledgeDocuments\KnowledgeDocumentResource;
use App\Filament\Resources\Products\ProductResource;
use App\Services\CompanyContext;
use App\Services\Crm\AiSetupChecklistService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * What is still missing before the AI can talk to real customers
 * (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.9, phase 8). Each step turns done by
 * itself when the data exists.
 */
class AiSetupChecklist extends Page
{
    protected static ?string $cluster = Crm::class;

    protected static ?int $navigationSort = 9;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected string $view = 'filament.pages.ai-setup-checklist';

    public static function getNavigationLabel(): string
    {
        return __('Setup checklist');
    }

    public function getTitle(): string
    {
        return __('Setup checklist');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('crm.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return list<array{key:string,title:string,hint:string,done:bool,detail:?string,url:?string}> */
    public function steps(): array
    {
        $company = app(CompanyContext::class)->company();
        if (! $company) {
            return [];
        }

        $texts = [
            'channel' => [__('Connect a chat channel'), __('Connect your Facebook page or WhatsApp number so customer messages reach the inbox.'), Integrations::class],
            'ai' => [__('Add an AI key and turn the AI on'), __('The AI cannot answer until a key is saved and the sales agent is switched on.'), Integrations::class],
            'about' => [__('Describe your business'), __('Write what you sell and who your customers are on the Agent Desk.'), AgentDesk::class],
            'products' => [__('Add products with photos'), __('Customers ask about products by name or by photo. Every active product needs a photo.'), ProductResource::class],
            'delivery' => [__('Set delivery charges'), __('The AI quotes delivery charges from your default courier. Enter them for each area.'), CourierProviderResource::class],
            'answers' => [__('Add answers to common questions'), __('Save FAQ answers or knowledge documents for things like warranty, returns policy and opening hours.'), KnowledgeDocumentResource::class],
            'alerts' => [__('Get alerts on your phone'), __('Install the app on your phone and allow notifications, so you know when the AI hands a chat to you.'), PushNotificationSettings::class],
            'test' => [__('Try the AI in the test chat'), __('Ask it a few real customer questions before customers do.'), AgentDesk::class],
        ];

        return collect(app(AiSetupChecklistService::class)->steps($company, auth()->user()))
            ->map(function (array $step) use ($texts): array {
                [$title, $hint, $target] = $texts[$step['key']];
                $target = ($step['key'] === 'answers' && ! KnowledgeDocumentResource::canAccess()) ? CompanyFaqResource::class : $target;

                return [
                    'key' => $step['key'],
                    'title' => $title,
                    'hint' => $hint,
                    'done' => $step['done'],
                    'detail' => $step['detail'],
                    'url' => $target::canAccess() ? $this->urlFor($target) : null,
                ];
            })
            ->all();
    }

    private function urlFor(string $target): string
    {
        return is_subclass_of($target, Page::class) ? $target::getUrl() : $target::getUrl('index');
    }
}
