<?php

namespace App\Filament\Resources\ConversationChannels;

use App\Filament\Clusters\Crm;
use App\Filament\Resources\ConversationChannels\Pages\CreateConversationChannel;
use App\Filament\Resources\ConversationChannels\Pages\EditConversationChannel;
use App\Filament\Resources\ConversationChannels\Pages\ListConversationChannels;
use App\Models\Company;
use App\Models\ConversationChannel;
use App\Services\CompanyContext;
use App\Services\Meta\MetaGraphException;
use App\Services\Meta\MetaGraphService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;

class ConversationChannelResource extends Resource
{
    protected static ?string $model = ConversationChannel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $cluster = Crm::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Chat Channel';

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Channel')->columnSpanFull()->schema([
                // In "All Companies" mode there is no active company for the
                // BelongsToCompany hook to assign, so the record would silently
                // fall back to the default company and "disappear" from the
                // owner's real company — make the target company explicit.
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
                    ->disabled(fn (?ConversationChannel $record): bool => $record !== null)
                    ->dehydrated(fn (?ConversationChannel $record): bool => $record === null)
                    ->helperText(fn (?ConversationChannel $record): string => $record
                        ? 'The owning company cannot be changed after creation because conversations and credentials are tenant-bound.'
                        : 'Select the company that will own this chat channel.'),
                Select::make('provider')
                    ->options(ConversationChannel::PROVIDERS)
                    ->required()
                    ->live()
                    ->native(false),
                TextInput::make('display_name')
                    ->label('Display Name')
                    ->placeholder('ZamZam Main Page')
                    ->required()
                    ->maxLength(255),
                TextInput::make('external_id')
                    ->label(fn (Get $get): string => $get('provider') === 'messenger'
                        ? 'Facebook Page ID'
                        : 'WhatsApp Phone Number ID')
                    ->helperText('WhatsApp: copy Phone Number ID from API Setup. This is not the WhatsApp Business Account (WABA) ID. Messenger: enter the Facebook Page ID.')
                    ->required()
                    ->maxLength(255)
                    // The provider+external_id pair is globally unique (the
                    // webhook resolves a channel by external_id alone), so a
                    // duplicate must fail as a form message, not a DB 500.
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('provider', $get('provider')),
                    )
                    ->validationMessages([
                        'unique' => 'A chat channel with this Phone Number ID / Page ID already exists (possibly under another company).',
                    ]),
                TextInput::make('waba_id')
                    ->label('WhatsApp Business Account ID (WABA ID)')
                    ->helperText('Find this separately in WhatsApp Manager. It is required to subscribe the app to messages webhooks.')
                    ->required(fn (Get $get): bool => (bool) $get('is_active') && $get('provider') === 'whatsapp')
                    ->visible(fn (Get $get): bool => $get('provider') === 'whatsapp')
                    ->maxLength(255),
                Toggle::make('auto_create_leads')
                    ->label('Auto-create leads for unknown contacts')
                    ->default(true),
                Toggle::make('is_active')
                    ->label('Active')
                    ->live()
                    ->default(true),
            ])->columns(2),

            Section::make('Credentials & Webhook Setup')
                ->description('1. Use a permanent system-user token. 2. Copy the callback URL and verify token into Meta. 3. Subscribe the WhatsApp app to the messages field. 4. Save, then run Test & Subscribe from the channel list.')
                ->columnSpanFull()->schema([
                    TextInput::make('access_token')
                        ->label('Access Token')
                        ->password()
                        ->revealable()
                        ->required(fn (Get $get, ?ConversationChannel $record): bool => (bool) $get('is_active') && blank($record?->access_token))
                        ->placeholder('Leave blank to keep the saved token…')
                        ->helperText('Stored encrypted. WhatsApp: use a permanent system-user token with messaging and management permissions. Messenger: use a Page access token.'),
                    TextInput::make('app_secret')
                        ->label('Meta App Secret')
                        ->password()
                        ->revealable()
                        ->required(fn (Get $get, ?ConversationChannel $record): bool => (bool) $get('is_active') && blank($record?->app_secret))
                        ->placeholder('Leave blank to keep the saved secret…')
                        ->helperText('Stored encrypted. Used to verify webhook signatures (X-Hub-Signature-256).'),
                    TextInput::make('verify_token')
                        ->label('Webhook Verify Token')
                        ->password()
                        ->revealable()
                        ->required(fn (Get $get, ?ConversationChannel $record): bool => (bool) $get('is_active') && blank($record?->verify_token))
                        ->placeholder('Leave blank to keep the saved verify token…')
                        ->helperText('Any private random string; paste the same value in the Meta webhook subscription form.'),
                    TextInput::make('webhook_url')
                        ->label('Webhook URL')
                        ->default(fn (): string => route('webhooks.meta.handle'))
                        ->readOnly()
                        ->dehydrated(false)
                        ->helperText('Paste this exact HTTPS callback URL into the Meta app webhook settings. Subscribe to the messages field.'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('company.name')
                ->label('Company')
                ->visible(fn (): bool => app(CompanyContext::class)->isAllCompanies()),
            TextColumn::make('display_name')->label('Name')->searchable(),
            TextColumn::make('provider')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => ConversationChannel::PROVIDERS[$state] ?? (string) $state),
            TextColumn::make('external_id')->label('External ID'),
            TextColumn::make('waba_id')
                ->label('WABA ID')
                ->placeholder('Not set')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('diagnostic_status')
                ->label('Connection')
                ->getStateUsing(fn (ConversationChannel $record): string => $record->diagnosticStatus())
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'Inbound confirmed', 'Connected' => 'success',
                    'Needs attention' => 'danger',
                    'Configured', 'Verify callback', 'Subscribe app', 'Callback verified' => 'warning',
                    default => 'gray',
                }),
            IconColumn::make('is_active')->label('Active')->boolean(),
            TextColumn::make('conversations_count')->counts('conversations')->label('Conversations'),
            TextColumn::make('last_webhook_at')
                ->label('Last Webhook')
                ->since()
                ->dateTimeTooltip()
                ->placeholder('Never')
                ->toggleable(),
            TextColumn::make('last_inbound_at')
                ->label('Last Inbound')
                ->since()
                ->dateTimeTooltip()
                ->placeholder('Never')
                ->toggleable(),
            TextColumn::make('last_outbound_at')
                ->label('Last Outbound')
                ->since()
                ->dateTimeTooltip()
                ->placeholder('Never')
                ->toggleable(),
            TextColumn::make('last_health_at')
                ->label('Last Connection Test')
                ->since()
                ->dateTimeTooltip()
                ->placeholder('Never')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('webhook_verified_at')
                ->label('Webhook Verified')
                ->since()
                ->dateTimeTooltip()
                ->placeholder('Never')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('webhook_subscribed_at')
                ->label('App Subscribed')
                ->since()
                ->dateTimeTooltip()
                ->placeholder('Never')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('last_error_at')
                ->label('Last Error At')
                ->since()
                ->dateTimeTooltip()
                ->placeholder('Never')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('last_error')
                ->label('Last Error')
                ->limit(45)
                ->tooltip(fn (ConversationChannel $record): ?string => $record->last_error)
                ->placeholder('None')
                ->color('danger')
                ->toggleable(isToggledHiddenByDefault: true),
        ])->recordActions([
            Action::make('testAndSubscribe')
                ->label(fn (ConversationChannel $record): string => $record->provider === 'whatsapp'
                    ? 'Test & Subscribe'
                    : 'Test & Subscribe')
                ->icon(Heroicon::OutlinedSignal)
                // WhatsApp Web (QR) channels are managed from the WhatsApp Web Login page.
                ->hidden(fn (ConversationChannel $record): bool => $record->isWeb())
                ->action(function (ConversationChannel $record): void {
                    try {
                        app(MetaGraphService::class)->testAndSubscribe($record);

                        Notification::make()
                            ->title($record->provider === 'whatsapp'
                                ? 'WhatsApp channel connected and subscribed.'
                                : 'Messenger connected; message echoes subscribed.')
                            ->body($record->provider === 'whatsapp'
                                ? 'Also confirm the callback is verified and the messages webhook field is enabled in Meta. Inbound confirmed appears only after a real customer message reaches the ERP.'
                                : null)
                            ->success()
                            ->send();
                    } catch (\Throwable $exception) {
                        if (! $exception instanceof MetaGraphException) {
                            report($exception);
                            $record->recordDiagnosticError('Connection test failed on the server. Check the application log.', 'connection');
                        }
                        Notification::make()
                            ->title('Meta connection needs attention')
                            ->body($record->fresh()->last_error ?: 'Check the channel IDs and credentials, then retry.')
                            ->danger()
                            ->send();
                    }
                }),
            EditAction::make()
                ->hidden(fn (ConversationChannel $record): bool => $record->isWeb()),
        ]);
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConversationChannels::route('/'),
            'create' => CreateConversationChannel::route('/create'),
            'edit' => EditConversationChannel::route('/{record}/edit'),
        ];
    }
}
