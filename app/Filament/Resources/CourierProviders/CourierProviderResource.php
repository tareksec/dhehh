<?php

namespace App\Filament\Resources\CourierProviders;

use App\Filament\Clusters\Courier;
use App\Filament\Resources\CourierProviders\Pages\CreateCourierProvider;
use App\Filament\Resources\CourierProviders\Pages\EditCourierProvider;
use App\Filament\Resources\CourierProviders\Pages\ListCourierProviders;
use App\Models\Company;
use App\Models\CourierProvider;
use App\Services\CompanyContext;
use App\Services\ECourierClient;
use App\Services\PathaoCourierClient;
use App\Services\RedxCourierClient;
use App\Services\SteadfastCourierClient;
use App\Support\CompanyScopedUnique;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CourierProviderResource extends Resource
{
    protected static ?string $model = CourierProvider::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $cluster = Courier::class;

    protected static ?string $navigationLabel = 'Providers';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Delivery Partner')
                ->columnSpanFull()
                ->schema([
                    Select::make('company_id')
                        ->label('Company')
                        ->options(fn (): array => Company::query()
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->required(fn (): bool => app(CompanyContext::class)->isAllCompanies())
                        ->visible(fn (): bool => app(CompanyContext::class)->isAllCompanies())
                        ->helperText('Select the company that will own this courier provider.'),
                    Select::make('driver')
                        ->label('Select Delivery Partner')
                        ->options(CourierProvider::DRIVERS)
                        ->default(CourierProvider::DRIVER_MANUAL)
                        ->required()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            $label = CourierProvider::DRIVERS[$state ?? CourierProvider::DRIVER_MANUAL] ?? 'Custom';

                            $set('name', $label);
                            $set('slug', Str::slug($label));
                        }),
                    TextInput::make('name')
                        ->label('Partner Name')
                        ->default('Custom')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            if (filled($state)) {
                                $set('slug', Str::slug($state));
                            }
                        }),
                    TextInput::make('settings.contact_person')
                        ->label('Contact Person')
                        ->maxLength(255),
                    TextInput::make('settings.phone')
                        ->label('Phone Number')
                        ->tel()
                        ->maxLength(30),
                    TextInput::make('settings.warehouse')
                        ->label('Warehouse')
                        ->placeholder('Select Warehouse')
                        ->maxLength(255),
                    TextInput::make('slug')
                        ->default('custom')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true, modifyRuleUsing: CompanyScopedUnique::rule()),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),
                    Toggle::make('is_default')
                        ->label('Set as default courier')
                        ->helperText('New orders (including from the storefront) are pre-assigned to this courier. Only one provider per company can be default — turning this on turns it off for any other provider.')
                        ->default(false),
                ])
                ->columns(2)
                ->collapsible(),

            Section::make('Set Delivery Fees')
                ->columnSpanFull()
                ->schema([
                    Select::make('settings.delivery_fee_mode')
                        ->label('Delivery Type')
                        ->options([
                            'regular' => 'Regular Delivery',
                            'express' => 'Express Delivery',
                        ])
                        ->default('regular')
                        ->native(false),
                    TextInput::make('settings.delivery_fees.inside')
                        ->label('Inside')
                        ->prefix('৳')
                        ->numeric()
                        ->default(0),
                    TextInput::make('settings.delivery_fees.outside')
                        ->label('Outside')
                        ->prefix('৳')
                        ->numeric()
                        ->default(0),
                    TextInput::make('settings.delivery_fees.suburb')
                        ->label('Suburb')
                        ->prefix('৳')
                        ->numeric()
                        ->default(0),
                ])
                ->columns(2)
                ->collapsible(),

            Section::make('Courier Return Cost')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('settings.return_costs.inside')
                        ->label('Inside')
                        ->prefix('৳')
                        ->numeric()
                        ->default(0),
                    TextInput::make('settings.return_costs.outside')
                        ->label('Outside')
                        ->prefix('৳')
                        ->numeric()
                        ->default(0),
                    TextInput::make('settings.return_costs.suburb')
                        ->label('Suburb')
                        ->prefix('৳')
                        ->numeric()
                        ->default(0),
                    TextInput::make('settings.cod_charge_percent')
                        ->label('COD Charge (Percentage)')
                        ->suffix('%')
                        ->numeric()
                        ->default(0),
                ])
                ->columns(2)
                ->collapsible(),

            Section::make('API Integration')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('settings.base_url')
                        ->label('Base URL')
                        ->url()
                        ->placeholder(fn (Get $get): string => match ($get('driver')) {
                            CourierProvider::DRIVER_PATHAO => PathaoCourierClient::DEFAULT_BASE_URL,
                            CourierProvider::DRIVER_REDX => RedxCourierClient::DEFAULT_BASE_URL,
                            CourierProvider::DRIVER_ECOURIER => ECourierClient::DEFAULT_BASE_URL,
                            default => SteadfastCourierClient::DEFAULT_BASE_URL,
                        })
                        ->helperText(fn (Get $get): string => match ($get('driver')) {
                            CourierProvider::DRIVER_PATHAO => 'Leave blank for live. Sandbox: '.PathaoCourierClient::SANDBOX_BASE_URL,
                            CourierProvider::DRIVER_REDX => 'Leave blank for live. Sandbox: '.RedxCourierClient::SANDBOX_BASE_URL,
                            CourierProvider::DRIVER_ECOURIER => 'Leave blank for live. Staging: '.ECourierClient::STAGING_BASE_URL,
                            default => 'Leave blank for the live default: '.SteadfastCourierClient::DEFAULT_BASE_URL,
                        }),
                    TextInput::make('credentials.api_key')
                        ->label('API Key')
                        ->password()
                        ->revealable()
                        ->required(fn (Get $get): bool => in_array($get('driver'), [CourierProvider::DRIVER_STEADFAST, CourierProvider::DRIVER_ECOURIER], true))
                        ->visible(fn (Get $get): bool => in_array($get('driver'), [CourierProvider::DRIVER_STEADFAST, CourierProvider::DRIVER_ECOURIER], true))
                        ->maxLength(255),
                    TextInput::make('credentials.secret_key')
                        ->label('Secret Key')
                        ->password()
                        ->revealable()
                        ->required(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_STEADFAST)
                        ->visible(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_STEADFAST)
                        ->maxLength(255),
                    Placeholder::make('steadfast_api_keys_note')
                        ->hiddenLabel()
                        ->content(__('From Steadfast\'s merchant panel → API → Your API keys. Issue a separate key for this ERP so retiring another plugin never takes it down. A key only sees the profile (business) it was issued for. These same keys power the Courier Fraud Check — no merchant login needed.'))
                        ->visible(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_STEADFAST)
                        ->columnSpanFull(),
                    TextInput::make('credentials.client_id')
                        ->label('Client ID')
                        ->required(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_PATHAO)
                        ->visible(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_PATHAO)
                        ->maxLength(255),
                    TextInput::make('credentials.client_secret')
                        ->label('Client Secret')
                        ->password()
                        ->revealable()
                        ->required(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_PATHAO)
                        ->visible(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_PATHAO)
                        ->maxLength(255),
                    TextInput::make('credentials.username')
                        ->label('Merchant Email / Username')
                        ->required(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_PATHAO)
                        ->visible(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_PATHAO)
                        ->maxLength(255),
                    TextInput::make('credentials.password')
                        ->label('Merchant Password')
                        ->password()
                        ->revealable()
                        ->required(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_PATHAO)
                        ->visible(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_PATHAO)
                        ->maxLength(255),
                    TextInput::make('settings.default_store_id')
                        ->label('Default Store ID')
                        ->numeric()
                        ->helperText('Pathao merchant panel > Stores. Used when the booking form does not override it.')
                        ->visible(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_PATHAO),
                    TextInput::make('credentials.access_token')
                        ->label('API Access Token')
                        ->password()
                        ->revealable()
                        ->required(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_REDX)
                        ->visible(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_REDX)
                        ->maxLength(2000),
                    TextInput::make('credentials.api_secret')
                        ->label('API Secret')
                        ->password()
                        ->revealable()
                        ->required(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_ECOURIER)
                        ->visible(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_ECOURIER)
                        ->maxLength(255),
                    TextInput::make('credentials.user_id')
                        ->label('User ID')
                        ->required(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_ECOURIER)
                        ->visible(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_ECOURIER)
                        ->maxLength(255),
                    TextInput::make('settings.default_package_code')
                        ->label('Default Package Code')
                        ->helperText('From E-Courier packages list. Used when the booking form does not override it.')
                        ->visible(fn (Get $get): bool => $get('driver') === CourierProvider::DRIVER_ECOURIER),
                    Placeholder::make('webhook_callback_url')
                        ->label(fn (Get $get): string => $get('driver') === CourierProvider::DRIVER_STEADFAST ? __('Webhook Callback URL') : __('Webhook URL'))
                        ->content(fn (?CourierProvider $record): string => $record?->exists
                            ? route('couriers.webhook', $record)
                            : __('Save this provider first — the webhook URL appears here after saving.'))
                        ->helperText(fn (Get $get): ?string => $get('driver') === CourierProvider::DRIVER_STEADFAST
                            ? __('Paste this into Steadfast\'s merchant panel → Webhook → Callback URL.')
                            : null)
                        ->columnSpanFull(),
                    TextInput::make('credentials.webhook_secret')
                        ->label(fn (Get $get): string => $get('driver') === CourierProvider::DRIVER_STEADFAST ? 'Webhook Auth Token' : 'Webhook Signing Secret')
                        ->password()
                        ->revealable()
                        ->maxLength(255)
                        ->regex(fn (Get $get): ?string => $get('driver') === CourierProvider::DRIVER_STEADFAST ? '/^[A-Za-z0-9\-_.=]+$/' : null)
                        ->validationMessages(['regex' => __('Steadfast allows only letters, digits and - _ . = in the auth token.')])
                        ->suffixAction(
                            Action::make('generateWebhookSecret')
                                ->icon(Heroicon::ArrowPath)
                                ->tooltip(__('Generate'))
                                ->action(fn (Set $set) => $set('credentials.webhook_secret', Str::random(40))),
                        )
                        ->helperText(fn (Get $get): string => $get('driver') === CourierProvider::DRIVER_STEADFAST
                            ? __('Generate a token here, then paste the exact same value into Steadfast\'s merchant panel → Webhook → Auth token. Steadfast sends it as Authorization: Bearer <token> and signs every request with it (X-Signature, HMAC-SHA256); both are checked.')
                            : 'Used to verify the signature this courier sends with each webhook request.'),
                    TextInput::make('settings.signature_header')
                        ->label('Webhook Signature Header')
                        ->placeholder(fn (Get $get): string => $get('driver') === CourierProvider::DRIVER_STEADFAST ? 'Authorization (default — leave blank)' : 'X-Courier-Signature (default — leave blank)')
                        ->maxLength(100)
                        ->helperText(fn (Get $get): string => $get('driver') === CourierProvider::DRIVER_STEADFAST
                            ? 'Steadfast sends its token in the standard Authorization header — leave this blank unless Steadfast tells you otherwise.'
                            : 'Leave blank to use this courier\'s standard header; only override if the courier documents a different one.'),
                    Toggle::make('settings.webhook_signature_required')
                        ->label('Require Webhook Signature')
                        ->default(true)
                        ->helperText('Turn off only if the courier confirms it does not sign/authenticate webhook requests — otherwise incoming webhooks are rejected.'),
                    TextInput::make('settings.tracking_url')
                        ->label('Tracking URL Template')
                        ->placeholder('https://example.com/track/{tracking_id}')
                        ->helperText('Use {tracking_id} as the placeholder.'),
                    TextInput::make('settings.label_url')
                        ->label('Label URL Template')
                        ->placeholder('https://example.com/label/{reference}')
                        ->helperText('Use {tracking_id} or {reference}.'),
                ])
                ->columns(2)
                ->visible(fn (Get $get): bool => in_array($get('driver'), CourierProvider::API_DRIVERS, true))
                ->collapsible(),

            Section::make('External Fraud Check (Merchant Panel Login)')
                ->columnSpanFull()
                ->description('Optional. Lets staff look up a phone number\'s delivery success/cancel history on this courier\'s own merchant panel before booking. These are the courier\'s website login credentials, separate from the API keys above.')
                ->schema([
                    TextInput::make('credentials.fraud_check.username')
                        ->label(fn (Get $get): string => $get('driver') === CourierProvider::DRIVER_REDX ? 'Merchant Phone Number' : 'Merchant Email / Username')
                        ->maxLength(255),
                    TextInput::make('credentials.fraud_check.password')
                        ->label('Merchant Password')
                        ->password()
                        ->revealable()
                        ->maxLength(255),
                ])
                ->columns(2)
                // Steadfast's fraud check uses its official API with the
                // booking API keys above, so it needs no merchant login.
                ->visible(fn (Get $get): bool => in_array($get('driver'), [
                    CourierProvider::DRIVER_PATHAO,
                    CourierProvider::DRIVER_REDX,
                ], true))
                ->collapsed(),

            Section::make('Monitoring & Alerts')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('settings.stale_after_days')
                        ->label('Stale Booking Alert (days)')
                        ->numeric()
                        ->minValue(1)
                        ->placeholder((string) CourierProvider::MONITORING_DEFAULTS['stale_after_days'])
                        ->helperText('Alert admins when a booking has no final status after this many days.'),
                    TextInput::make('settings.sync_failure_alert_threshold')
                        ->label('Sync Failure Alert Threshold')
                        ->numeric()
                        ->minValue(1)
                        ->placeholder((string) CourierProvider::MONITORING_DEFAULTS['sync_failure_alert_threshold'])
                        ->helperText('Alert admins after this many consecutive sync failures.'),
                    TextInput::make('settings.sync_batch_limit')
                        ->label('Sync Batch Limit')
                        ->numeric()
                        ->minValue(1)
                        ->placeholder((string) CourierProvider::MONITORING_DEFAULTS['sync_batch_limit'])
                        ->helperText('Maximum bookings synced per scheduled run.'),
                    TextInput::make('settings.sync_cooldown_minutes')
                        ->label('Sync Cooldown (minutes)')
                        ->numeric()
                        ->minValue(1)
                        ->placeholder((string) CourierProvider::MONITORING_DEFAULTS['sync_cooldown_minutes'])
                        ->helperText('Skip bookings synced more recently than this.'),
                ])
                ->columns(2)
                ->visible(fn (Get $get): bool => in_array($get('driver'), CourierProvider::API_DRIVERS, true))
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('driver')
                    ->label('Partner')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => CourierProvider::DRIVERS[$state ?? ''] ?? str($state)->headline()->toString()),
                TextColumn::make('settings.contact_person')
                    ->label('Contact Person')
                    ->placeholder('-'),
                TextColumn::make('settings.phone')
                    ->label('Phone Number')
                    ->placeholder('-'),
                TextColumn::make('settings.warehouse')
                    ->label('Warehouse')
                    ->placeholder('-'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                TextColumn::make('last_synced_at')
                    ->label('Last Sync')
                    ->dateTime()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('sync_failure_count')
                    ->label('Sync Failures')
                    ->badge()
                    ->color(fn (?int $state): string => ($state ?? 0) > 0 ? 'danger' : 'success')
                    ->tooltip(fn (CourierProvider $record): ?string => $record->last_sync_error)
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('checkBalance')
                    ->label('Balance')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->visible(fn (CourierProvider $record): bool => $record->driver === CourierProvider::DRIVER_STEADFAST
                        && filled($record->credentials['api_key'] ?? null)
                        && filled($record->credentials['secret_key'] ?? null))
                    ->action(function (CourierProvider $record): void {
                        try {
                            $response = app(SteadfastCourierClient::class)->balance($record);
                            $balance = $response['current_balance'] ?? null;

                            if ($balance === null) {
                                throw new \RuntimeException($response['message'] ?? 'Steadfast did not return a balance.');
                            }

                            Notification::make()
                                ->title('Steadfast balance')
                                ->body('Current balance: '.MoneyFormatter::currency((float) $balance))
                                ->success()
                                ->send();
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('Balance check failed')
                                ->body(self::steadfastFailureMessage($record, $exception))
                                ->danger()
                                ->send();
                        }
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Turns a failed Steadfast call into what to do about it, per the
     * response-code table in Steadfast's API guide.
     */
    public static function steadfastFailureMessage(CourierProvider $record, \Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return collect($exception->errors())->flatten()->implode(' ');
        }

        if (! $exception instanceof RequestException) {
            return app(SteadfastCourierClient::class)->ping($record)
                ? $exception->getMessage()
                : __('Steadfast could not be reached. Check the Base URL and this server\'s internet connection.');
        }

        return match ($exception->response->status()) {
            401 => __('Steadfast rejected the API key or secret key (or they belong to a different business profile). Fix the keys before trying again — repeated failures lock the key out for 60 minutes.'),
            403 => __('Steadfast says this account is not allowed right now — it may be inactive or its ownership (KYC) details need resubmitting.'),
            429 => __('Too many requests or too many failed attempts — Steadfast has paused this key. Wait before trying again.'),
            default => __('Steadfast answered with error :status. Try again shortly.', ['status' => $exception->response->status()]),
        };
    }

    public static function canViewAny(): bool
    {
        return SchemaFacade::hasTable('courier_providers') && (Auth::user()?->hasPermission('sales.view') ?? false);
    }

    public static function canCreate(): bool
    {
        return SchemaFacade::hasTable('courier_providers')
            && (app(CompanyContext::class)->hasCompany()
                || (app(CompanyContext::class)->isAllCompanies() && (Auth::user()?->isSuperAdmin() ?? false)))
            && (Auth::user()?->hasPermission('sales.update') ?? false);
    }

    public static function canEdit(Model $record): bool
    {
        return SchemaFacade::hasTable('courier_providers') && (Auth::user()?->hasPermission('sales.update') ?? false);
    }

    public static function canDelete(Model $record): bool
    {
        return SchemaFacade::hasTable('courier_providers') && (Auth::user()?->isSuperAdmin() ?? false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourierProviders::route('/'),
            'create' => CreateCourierProvider::route('/create'),
            'edit' => EditCourierProvider::route('/{record}/edit'),
        ];
    }
}
