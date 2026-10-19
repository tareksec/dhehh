<?php

namespace App\Filament\Resources\Broadcasts\Schemas;

use App\Models\Broadcast;
use App\Models\ConversationChannel;
use App\Models\Lead;
use App\Services\CompanyContext;
use App\Services\Meta\MetaGraphException;
use App\Services\Meta\MetaGraphService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

class BroadcastForm
{
    public static function configure(Schema $schema): Schema
    {
        $locked = fn (?Broadcast $record): bool => $record !== null && ! $record->isEditable();

        return $schema
            ->columns(1)
            ->components([
                Section::make('Broadcast')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->disabled($locked),
                        Select::make('audience_type')
                            ->label('Audience')
                            ->options(Broadcast::AUDIENCE_TYPES)
                            ->required(fn (Get $get): bool => $get('channel') !== 'messenger')
                            ->visible(fn (Get $get): bool => $get('channel') !== 'messenger')
                            ->live()
                            ->native(false)
                            ->disabled($locked),
                        Select::make('lead_status_filter')
                            ->label('Lead status')
                            ->options(Lead::STATUSES)
                            ->multiple()
                            ->native(false)
                            ->visible(fn (Get $get): bool => in_array($get('audience_type'), ['leads', 'both'], true))
                            ->helperText('Leave empty to include every status.')
                            ->disabled($locked),
                        Select::make('lead_source_filter')
                            ->label('Lead source')
                            ->options(Lead::SOURCES)
                            ->multiple()
                            ->native(false)
                            ->visible(fn (Get $get): bool => in_array($get('audience_type'), ['leads', 'both'], true))
                            ->helperText('Leave empty to include every source.')
                            ->disabled($locked),
                    ])
                    ->columns(2),

                Section::make('Message')
                    ->columnSpanFull()
                    ->description('WhatsApp goes out as a Meta-approved template only, since this is a cold, business-initiated send. SMS uses the gateway configured under Storefront Settings → Customer Notifications.')
                    ->schema([
                        Select::make('channel')
                            ->options(Broadcast::CHANNELS)
                            ->required()
                            ->live()
                            ->native(false)
                            ->disabled($locked),
                        Select::make('whatsapp_channel_id')
                            ->label('WhatsApp Chat Channel')
                            ->options(function (): array {
                                $companyId = app(CompanyContext::class)->company()?->getKey();

                                return $companyId ? ConversationChannel::withoutGlobalScopes()
                                    ->where('company_id', $companyId)
                                    ->where('provider', 'whatsapp')
                                    ->where('is_active', true)
                                    ->orderBy('display_name')
                                    ->pluck('display_name', 'id')
                                    ->all() : [];
                            })
                            ->live()
                            ->native(false)
                            ->visible(fn (Get $get): bool => in_array($get('channel'), ['whatsapp', 'both'], true))
                            ->required(fn (Get $get): bool => in_array($get('channel'), ['whatsapp', 'both'], true))
                            ->disabled($locked),
                        Select::make('whatsapp_template_name')
                            ->label('Approved WhatsApp template')
                            ->options(function (Get $get): array {
                                return static::approvedTemplates((int) $get('whatsapp_channel_id'))
                                    ->mapWithKeys(fn (array $template): array => [
                                        $template['name'] => "{$template['name']} ({$template['language']})",
                                    ])
                                    ->all();
                            })
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                $language = static::approvedTemplates((int) $get('whatsapp_channel_id'))
                                    ->firstWhere('name', $state)['language'] ?? null;

                                if ($language) {
                                    $set('whatsapp_template_language', $language);
                                }
                            })
                            ->live()
                            ->searchable()
                            ->native(false)
                            ->helperText('Only APPROVED templates from the selected channel\'s WABA are listed. Save the channel and run Test & Subscribe first if this is empty.')
                            ->visible(fn (Get $get): bool => in_array($get('channel'), ['whatsapp', 'both'], true))
                            ->required(fn (Get $get): bool => in_array($get('channel'), ['whatsapp', 'both'], true))
                            ->disabled($locked),
                        TextInput::make('whatsapp_template_language')
                            ->label('Template language')
                            ->maxLength(10)
                            ->default('bn')
                            ->visible(fn (Get $get): bool => in_array($get('channel'), ['whatsapp', 'both'], true))
                            ->disabled($locked),
                        Select::make('messenger_channel_id')
                            ->label(__('Facebook Page'))
                            ->options(function (): array {
                                $companyId = app(CompanyContext::class)->company()?->getKey();

                                return $companyId ? ConversationChannel::withoutGlobalScopes()
                                    ->where('company_id', $companyId)
                                    ->where('provider', 'messenger')
                                    ->where('is_active', true)
                                    ->orderBy('display_name')
                                    ->pluck('display_name', 'id')
                                    ->all() : [];
                            })
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('channel') === 'messenger')
                            ->required(fn (Get $get): bool => $get('channel') === 'messenger')
                            ->helperText(__('The audience is this Page\'s chats that wrote to it in the last :hours hours. Messenger allows nothing else.', ['hours' => Broadcast::MESSENGER_WINDOW_HOURS]))
                            ->disabled($locked),
                        Textarea::make('messenger_text')
                            ->label(__('Messenger message'))
                            ->rows(4)
                            ->maxLength(1000)
                            ->placeholder('Hi {{name}}, ...')
                            ->helperText(__('Use {{name}} for the customer\'s name. Before sending, use "Send test" on the list to send this exact text to your own chat. Changing the text asks for a new test.'))
                            ->visible(fn (Get $get): bool => $get('channel') === 'messenger')
                            ->required(fn (Get $get): bool => $get('channel') === 'messenger')
                            ->columnSpanFull()
                            ->disabled($locked),
                        Textarea::make('sms_body')
                            ->label('SMS message')
                            ->rows(3)
                            ->maxLength(500)
                            ->placeholder('Hi {{name}}, ...')
                            ->helperText('Use {{name}} as a placeholder. Sent as-is (SMS has no approval requirement).')
                            ->visible(fn (Get $get): bool => in_array($get('channel'), ['sms', 'both'], true))
                            ->required(fn (Get $get): bool => in_array($get('channel'), ['sms', 'both'], true))
                            ->columnSpanFull()
                            ->disabled($locked),
                    ])
                    ->columns(2),
            ]);
    }

    /** @return Collection<int, array{name: string, language: string, category: ?string}> */
    protected static function approvedTemplates(?int $channelId): Collection
    {
        if (! $channelId) {
            return collect();
        }

        $channel = ConversationChannel::withoutGlobalScopes()->find($channelId);

        if (! $channel) {
            return collect();
        }

        try {
            return collect(app(MetaGraphService::class)->listMessageTemplates($channel));
        } catch (MetaGraphException) {
            return collect();
        }
    }
}
