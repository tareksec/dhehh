<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Crm;
use App\Jobs\RefreshWhatsAppWebNamesJob;
use App\Models\Company;
use App\Models\ConversationChannel;
use App\Models\ConversationMessage;
use App\Models\WhatsAppContact;
use App\Services\CompanyContext;
use App\Services\WhatsAppWeb\WhatsAppWebBridgeClient;
use App\Services\WhatsAppWeb\WhatsAppWebBridgeException;
use App\Services\WhatsAppWeb\WhatsAppWebSettingsService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Links the WhatsApp Business App the way web.whatsapp.com does - by
 * scanning a QR code - through the self-hosted whatsapp-bridge service, and
 * archives every chat (full history + live) into the ERP. The archive is
 * independent of WhatsApp: logging out, deleting the channel or the number
 * being banned never removes archived conversations, messages or contacts.
 */
class WhatsAppWebLogin extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static ?string $cluster = Crm::class;

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.whats-app-web-login';

    public array $settings = [];

    public string $deviceName = '';

    /** @var array<int, array<string, mixed>> Live bridge state per channel id. */
    public array $live = [];

    public static function getNavigationLabel(): string
    {
        return __('WhatsApp Web Login');
    }

    public function getTitle(): string
    {
        return __('WhatsApp Web Login (QR)');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public function mount(WhatsAppWebSettingsService $service): void
    {
        $company = $this->company();
        abort_unless($company !== null, 404);

        $this->fillSettings($service, $company);
        $this->refreshStatuses();
    }

    public function saveSettings(WhatsAppWebSettingsService $service): void
    {
        $this->validate([
            'settings.bridge_url' => ['required', 'url:http,https', 'max:255'],
            'settings.api_key' => [($this->settings['has_api_key'] ?? false) ? 'nullable' : 'required', 'string', 'min:24', 'max:255'],
        ]);

        $company = $this->company();
        abort_unless($company !== null, 404);

        $service->save($company, $this->settings);
        $this->fillSettings($service, $company->fresh());

        Notification::make()->title(__('WhatsApp Web bridge settings saved'))->success()->send();
    }

    /** Creates a new archive channel and asks the bridge for a QR code. */
    public function linkDevice(): void
    {
        $this->validate(['deviceName' => ['required', 'string', 'max:100']]);

        $company = $this->company();
        abort_unless($company !== null, 404);

        $channel = ConversationChannel::query()->create([
            'company_id' => $company->getKey(),
            'provider' => 'whatsapp',
            'connection_type' => 'web',
            'web_status' => 'pending',
            // Bridge session id; never a Meta phone_number_id.
            'external_id' => 'web-'.Str::lower((string) Str::ulid()),
            'display_name' => trim($this->deviceName),
            // HMAC secret the bridge signs every webhook with.
            'app_secret' => Str::random(48),
            'auto_create_leads' => true,
            'is_active' => true,
        ]);

        $this->deviceName = '';
        $this->connect($channel->getKey());
    }

    /** Starts / restarts the bridge session (shows a fresh QR code when not linked). */
    public function connect(int $channelId): void
    {
        $channel = $this->findChannel($channelId);
        $bridge = app(WhatsAppWebBridgeClient::class);

        try {
            $this->live[$channel->getKey()] = $bridge->connect($channel);
            $this->syncLocalStatus($channel, $this->live[$channel->getKey()]);
        } catch (WhatsAppWebBridgeException $exception) {
            Notification::make()->title(__('Could not start WhatsApp Web'))->body($exception->getMessage())->danger()->send();
        }
    }

    /** Unlinks the device from WhatsApp. Archived chats stay in the ERP. */
    public function logout(int $channelId): void
    {
        $channel = $this->findChannel($channelId);
        $bridge = app(WhatsAppWebBridgeClient::class);

        try {
            $bridge->logout($channel);
        } catch (WhatsAppWebBridgeException $exception) {
            Notification::make()->title(__('Could not log out on the bridge'))->body($exception->getMessage())->warning()->send();
        }

        $channel->forceFill(['web_status' => 'logged_out', 'web_disconnected_at' => now()])->saveQuietly();
        unset($this->live[$channel->getKey()]);

        Notification::make()
            ->title(__('WhatsApp Web logged out'))
            ->body(__('All archived chats, messages and contacts are kept in the ERP.'))
            ->success()
            ->send();
    }

    /** Gives chats without a name the name WhatsApp shared for their contact (runs in the background). */
    public function refreshNames(int $channelId): void
    {
        $channel = $this->findChannel($channelId);

        RefreshWhatsAppWebNamesJob::dispatch($channel->getKey());

        Notification::make()
            ->title(__('Looking up missing names'))
            ->body(__('Chats without a name get the name WhatsApp shares for that contact. This runs in the background and can take a few minutes.'))
            ->success()
            ->send();
    }

    /** Polled by the page while a QR code is on screen or a sync is running. */
    public function refreshStatuses(): void
    {
        $company = $this->company();

        if (! $company || ! app(WhatsAppWebSettingsService::class)->isConfigured($company)) {
            return;
        }

        $bridge = app(WhatsAppWebBridgeClient::class);

        foreach ($this->channels() as $channel) {
            if (in_array($channel->web_status, ['logged_out', 'banned'], true) && ! isset($this->live[$channel->getKey()])) {
                continue;
            }

            try {
                $state = $bridge->status($channel);
            } catch (WhatsAppWebBridgeException) {
                $state = null;
            }

            if ($state === null) {
                unset($this->live[$channel->getKey()]);

                continue;
            }

            $this->live[$channel->getKey()] = $state;
            $this->syncLocalStatus($channel, $state);
        }
    }

    /** Mirrors a reportable live bridge state locally in case its webhook is still queued. */
    protected function syncLocalStatus(ConversationChannel $channel, array $state): void
    {
        $status = (string) ($state['status'] ?? '');

        if (! array_key_exists($status, ConversationChannel::WEB_STATUSES) || $channel->web_status === $status) {
            return;
        }

        $phone = preg_replace('/\D+/', '', (string) ($state['phone'] ?? ''));

        $channel->forceFill(array_filter([
            'web_status' => $status,
            'web_phone' => $phone !== '' ? $phone : null,
            'web_connected_at' => $status === 'connected' ? now() : null,
        ]))->saveQuietly();
    }

    public function shouldPoll(): bool
    {
        return collect($this->live)->contains(fn (array $state): bool => in_array($state['status'] ?? null, ['starting', 'qr', 'connecting', 'reconnecting'], true)
            || (($state['status'] ?? null) === 'connected' && ($state['history_progress'] ?? 100) < 100));
    }

    /** @return Collection<int, ConversationChannel> */
    public function channels(): Collection
    {
        $company = $this->company();

        return ConversationChannel::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company?->getKey())
            ->where('connection_type', 'web')
            ->withCount('conversations')
            ->latest('id')
            ->get();
    }

    public function messageCount(ConversationChannel $channel): int
    {
        return ConversationMessage::query()
            ->whereHas('conversation', fn ($query) => $query->withoutGlobalScopes()->where('channel_id', $channel->getKey()))
            ->count();
    }

    public function contactCount(ConversationChannel $channel): int
    {
        return WhatsAppContact::query()->withoutGlobalScopes()->where('channel_id', $channel->getKey())->count();
    }

    protected function findChannel(int $channelId): ConversationChannel
    {
        return ConversationChannel::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->company()?->getKey())
            ->where('connection_type', 'web')
            ->findOrFail($channelId);
    }

    protected function fillSettings(WhatsAppWebSettingsService $service, Company $company): void
    {
        $stored = $service->all($company);

        $this->settings = [
            'bridge_url' => $stored['bridge_url'],
            'api_key' => '', // never round-trip the stored key to the browser
            'has_api_key' => filled($stored['api_key']),
            'ready' => $service->isConfigured($company),
        ];
    }

    /** Falls back to the default company when "All Companies" is selected. */
    protected function company(): ?Company
    {
        return app(CompanyContext::class)->company() ?? Company::defaultCompany();
    }
}
