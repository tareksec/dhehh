<?php

namespace App\Filament\Resources\Broadcasts\Tables;

use App\Models\Broadcast;
use App\Models\Conversation;
use App\Services\Crm\BroadcastService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class BroadcastsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('audience_type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === Broadcast::MESSENGER_AUDIENCE
                        ? __('Recent Messenger chats')
                        : (Broadcast::AUDIENCE_TYPES[$state] ?? (string) $state)),
                TextColumn::make('channel')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Broadcast::CHANNELS[$state] ?? (string) $state),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'queued', 'sending' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                        'cancelled' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => Broadcast::STATUSES[$state] ?? (string) $state),
                TextColumn::make('recipients_count')->label('Recipients')->sortable(),
                TextColumn::make('sent_count')->label('Sent')->sortable(),
                TextColumn::make('failed_count')->label('Failed')->sortable(),
                TextColumn::make('creator.name')->label('Created By')->placeholder('-'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(Broadcast::STATUSES),
                SelectFilter::make('channel')->options(Broadcast::CHANNELS),
            ])
            ->recordActions([
                Action::make('buildRecipients')
                    ->label('Build recipient list')
                    ->icon('heroicon-o-users')
                    ->visible(fn (Broadcast $record): bool => $record->isEditable())
                    ->action(function (Broadcast $record): void {
                        $count = app(BroadcastService::class)->buildRecipients($record);
                        Notification::make()
                            ->title("Recipient list built: {$count} recipient(s).")
                            ->success()
                            ->send();
                    }),
                Action::make('sendTest')
                    ->label(__('Send test'))
                    ->icon('heroicon-o-beaker')
                    ->color('warning')
                    ->visible(fn (Broadcast $record): bool => $record->isMessenger() && $record->isEditable())
                    ->modalDescription(__('Pick your own chat with the Page. It gets the exact message, so you see what customers will see. Write to the Page from your phone first if your chat is not in the list.'))
                    ->schema([
                        Select::make('conversation_id')
                            ->label(__('Your chat'))
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->options(fn (Broadcast $record): array => app(BroadcastService::class)->messengerAudience($record)
                                ->orderByDesc('last_message_at')->limit(50)->get(['id', 'contact_name'])
                                ->mapWithKeys(fn (Conversation $conversation): array => [$conversation->getKey() => $conversation->contact_name ?: __('Chat').' #'.$conversation->getKey()])
                                ->all())
                            ->getSearchResultsUsing(fn (string $search, Broadcast $record): array => app(BroadcastService::class)->messengerAudience($record)
                                ->where('contact_name', 'like', '%'.$search.'%')->limit(50)->get(['id', 'contact_name'])
                                ->mapWithKeys(fn (Conversation $conversation): array => [$conversation->getKey() => $conversation->contact_name ?: __('Chat').' #'.$conversation->getKey()])
                                ->all()),
                    ])
                    ->action(function (Broadcast $record, array $data): void {
                        $conversation = app(BroadcastService::class)->messengerAudience($record)->find((int) $data['conversation_id']);
                        try {
                            if (! $conversation) {
                                throw ValidationException::withMessages(['conversation' => __('That chat is no longer in the audience.')]);
                            }
                            app(BroadcastService::class)->sendTest($record, $conversation);
                        } catch (ValidationException $exception) {
                            Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();

                            return;
                        }
                        Notification::make()->title(__('Test sent. You can send the broadcast now.'))->success()->send();
                    }),
                Action::make('send')
                    ->label('Send')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Broadcast $record): string => "This will message {$record->recipients_count} recipient(s). This cannot be undone.")
                    ->visible(fn (Broadcast $record): bool => $record->isEditable() && $record->recipients_count > 0)
                    ->disabled(fn (Broadcast $record): bool => $record->needsTest())
                    ->tooltip(fn (Broadcast $record): ?string => $record->needsTest() ? __('Send a test to your own chat first.') : null)
                    ->action(function (Broadcast $record): void {
                        try {
                            app(BroadcastService::class)->queue($record);
                        } catch (ValidationException $exception) {
                            Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();

                            return;
                        }
                        Notification::make()
                            ->title('Broadcast queued for sending.')
                            ->success()
                            ->send();
                    }),
                EditAction::make()
                    ->visible(fn (Broadcast $record): bool => $record->isEditable()),
            ]);
    }
}
