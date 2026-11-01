<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Models\Company;
use App\Models\ConversationChannel;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use App\Services\Meta\MetaGraphService;
use App\Services\OrderSummaryCardImageService;
use App\Services\OrderWhatsAppSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Where the owner switches on the automatic WhatsApp order card (owner request
 * 2026-10-22): the customer gets the order summary card as a picture when the
 * order is created and again at every new stage. It goes through the official
 * WhatsApp Cloud API with an approved template that has an image header; the
 * sending is in OrderWhatsAppCardService, the picture is drawn by
 * OrderSummaryCardImageService. The SMS is separate (SMS Gateway → Message templates).
 */
class OrderWhatsAppCard extends Page
{
    /** The template must have exactly these body variables: name, order number, status, total, due. */
    public const BODY_VARIABLES = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.order-whatsapp-card';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Order WhatsApp card');
    }

    public function getTitle(): string
    {
        return __('Order WhatsApp card');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();
        $context = app(CompanyContext::class);

        if (! $user?->canManageSettings()) {
            return false;
        }

        if ($context->isAllCompanies()) {
            return $user->isSuperAdmin();
        }

        $company = $context->company();

        return (bool) ($company && $user->canAccessCompany((int) $company->getKey()));
    }

    public function company(): ?Company
    {
        $context = app(CompanyContext::class);

        return $context->isAllCompanies() ? null : $context->company();
    }

    public function mount(): void
    {
        $company = $this->company();

        if (! $company) {
            return;
        }

        $settings = app(OrderWhatsAppSettings::class)->get($company);

        $this->form->fill([
            'enabled' => $settings['enabled'],
            'channel_id' => $settings['channel_id'],
            'template_choice' => $settings['template'] !== '' ? $settings['template'].'|'.$settings['language'] : null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $images = app(OrderSummaryCardImageService::class);

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('How it works'))
                    ->description(__('The customer is sent the order summary card as a picture on WhatsApp when the order is created and every time it reaches a new stage (confirmed, processing, shipping, delivered, completed, cancelled, returned, refunded). It goes through the official WhatsApp Cloud API only: a QR-linked WhatsApp Web channel is never used for automatic messages. A number without WhatsApp is skipped. Orders of resellers and imported past orders are never sent, nor customers who opted out.'))
                    ->schema([
                        Text::make(fn (): string => $images->canRender()
                            ? __('This server can draw the card picture.')
                            : __('This server cannot draw the card picture yet: :problem. Until that is fixed no card is sent (the SMS is not affected).', ['problem' => (string) $images->problem()]))
                            ->color(fn (): string => $images->canRender() ? 'success' : 'danger'),
                    ]),
                Section::make(__('Settings'))
                    ->schema([
                        Toggle::make('enabled')
                            ->label(__('Send the order card on WhatsApp automatically'))
                            ->live(),
                        Select::make('channel_id')
                            ->label(__('WhatsApp channel'))
                            ->options(fn (): array => $this->company() ? app(OrderWhatsAppSettings::class)->channelOptions($this->company()) : [])
                            ->helperText(__('Only your active Cloud API or Coexistence channels are listed. Add one under Chat Channels.'))
                            ->live()
                            ->required(fn (callable $get): bool => (bool) $get('enabled')),
                        Select::make('template_choice')
                            ->label(__('Approved template'))
                            ->options(fn (callable $get): array => $this->templateOptions((int) $get('channel_id')))
                            ->searchable()
                            ->helperText(__('Create the template in Meta Business Manager with an Image header and a body with exactly five variables, in this order: {{1}} customer name, {{2}} order number, {{3}} status, {{4}} total, {{5}} amount due. Only approved templates like that are listed.'))
                            ->required(fn (callable $get): bool => (bool) $get('enabled')),
                    ]),
            ]);
    }

    /**
     * Approved templates of the chosen channel that fit the card: an image header and five body variables.
     *
     * @return array<string, string> "name|language" => label
     */
    protected function templateOptions(int $channelId): array
    {
        $company = $this->company();
        $channel = $company && $channelId > 0
            ? ConversationChannel::withoutGlobalScopes()
                ->where('company_id', $company->getKey())
                ->where('provider', 'whatsapp')
                ->whereIn('connection_type', OrderWhatsAppSettings::CHANNEL_TYPES)
                ->whereKey($channelId)
                ->first()
            : null;

        if (! $channel) {
            return [];
        }

        try {
            return collect(app(MetaGraphService::class)->listMessageTemplates($channel))
                ->filter(fn (array $template): bool => $template['has_image_header'] && $template['body_variables'] === self::BODY_VARIABLES)
                ->mapWithKeys(fn (array $template): array => [$template['name'].'|'.$template['language'] => $template['name'].' ('.$template['language'].')'])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function save(): void
    {
        $company = $this->company();

        abort_unless($company !== null && static::canAccess(), 404);

        $data = $this->form->getState();
        $enabled = (bool) ($data['enabled'] ?? false);
        [$template, $language] = array_pad(explode('|', (string) ($data['template_choice'] ?? ''), 2), 2, '');

        if ($enabled && (blank($data['channel_id'] ?? null) || $template === '')) {
            throw ValidationException::withMessages([
                'data.template_choice' => __('Choose the WhatsApp channel and an approved template first.'),
            ]);
        }

        $settings = app(OrderWhatsAppSettings::class);
        $before = $settings->get($company);
        $settings->save($company, [
            'enabled' => $enabled,
            'channel_id' => $data['channel_id'] ?? null,
            'template' => $template,
            'language' => $language,
        ]);

        app(AuditLogService::class)->record('order_whatsapp_card.saved', $company, $before, $settings->get($company->refresh()));

        Notification::make()->title(__('Order WhatsApp card settings saved'))->success()->send();
    }
}
