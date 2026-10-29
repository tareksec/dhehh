<?php

namespace App\Livewire;

use App\Jobs\DispatchWebsiteWebhookJob;
use App\Models\StorefrontSetting;
use App\Models\WebsiteWebhookDelivery;
use App\Services\CompanyContext;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The website webhook delivery log (WebsiteWebhookDelivery), embedded on the
 * Integrations page's "Website API" tab next to WebsiteApiKeysTable. "Send
 * again" queues the same event and data to the same URL with the secret saved
 * on that site today; the resend is logged as a new row.
 */
class WebsiteWebhookDeliveriesTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canManageSettings() ?? false, 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => WebsiteWebhookDelivery::query()->where('company_id', $this->companyId()))
            ->heading(__('Webhook delivery log'))
            ->description(__('Every notification sent to your website, newest first. A failed delivery shows the HTTP status or error your website returned.'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Sent'))->dateTime('d M Y, h:i:s A')->sortable(),
                TextColumn::make('event')->label(__('Event'))->badge()->searchable(),
                TextColumn::make('sku')->label(__('SKU'))->searchable()->copyable()->placeholder('-'),
                TextColumn::make('status')
                    ->label(__('Result'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === WebsiteWebhookDelivery::STATUS_DELIVERED ? __('Delivered') : __('Failed'))
                    ->color(fn (string $state): string => $state === WebsiteWebhookDelivery::STATUS_DELIVERED ? 'success' : 'danger'),
                TextColumn::make('response_status')->label(__('HTTP status'))->placeholder('-'),
                TextColumn::make('attempts')->label(__('Attempts'))->numeric(),
                TextColumn::make('response_excerpt')->label(__('Response'))->limit(80)->wrap()->placeholder('-')->toggleable(),
                TextColumn::make('error')->label(__('Error'))->limit(80)->wrap()->placeholder('-')->toggleable(),
                TextColumn::make('url')->label(__('URL'))->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Result'))
                    ->options([
                        WebsiteWebhookDelivery::STATUS_DELIVERED => __('Delivered'),
                        WebsiteWebhookDelivery::STATUS_FAILED => __('Failed'),
                    ]),
                SelectFilter::make('event')
                    ->label(__('Event'))
                    ->options(fn (): array => WebsiteWebhookDelivery::query()
                        ->where('company_id', $this->companyId())
                        ->distinct()
                        ->orderBy('event')
                        ->pluck('event', 'event')
                        ->all()),
            ])
            ->recordActions([$this->resendAction()])
            ->emptyStateHeading(__('No webhook deliveries yet'))
            ->emptyStateDescription(__('Deliveries appear here once "Send webhooks" is on and something changes, or after "Send test webhook".'));
    }

    protected function resendAction(): Action
    {
        return Action::make('resend')
            ->label(__('Send again'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->action(function (WebsiteWebhookDelivery $record): void {
                $secret = $this->secretFor($record->url);

                if ($secret === null) {
                    Notification::make()
                        ->title(__('This webhook URL is no longer saved on any site'))
                        ->warning()
                        ->send();

                    return;
                }

                DispatchWebsiteWebhookJob::dispatch($record->url, $secret, $record->event, $record->payload ?? [], $this->companyId());

                Notification::make()->title(__('Webhook queued again'))->success()->send();
            });
    }

    protected function secretFor(string $url): ?string
    {
        foreach (StorefrontSetting::withoutGlobalScopes()->where('company_id', $this->companyId())->get() as $setting) {
            $credentials = $setting->website_api_credentials ?? [];

            if ((string) ($credentials['webhook_url'] ?? '') === $url && filled($credentials['webhook_secret'] ?? null)) {
                return (string) $credentials['webhook_secret'];
            }
        }

        return null;
    }

    public function render(): View
    {
        return view('livewire.website-webhook-deliveries-table');
    }

    protected function companyId(): int
    {
        return (int) app(CompanyContext::class)->id();
    }
}
