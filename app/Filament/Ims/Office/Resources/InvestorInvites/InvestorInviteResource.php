<?php

namespace App\Filament\Ims\Office\Resources\InvestorInvites;

use App\Filament\Ims\Office\Resources\InvestorInvites\Pages\ListInvestorInvites;
use App\Ims\Onboarding\InviteService;
use App\Models\InvestorInvite;
use App\Support\ImsNumbers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Office invites to join IMS (IMS-V2/PRD.md FR-04): send a link to a phone
 * or an e-mail address, see whether it was used, cancel it.
 */
class InvestorInviteResource extends Resource
{
    protected static ?string $model = InvestorInvite::class;

    protected static ?string $slug = 'invites';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('invite');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Invites');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('investments.portal_manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('contact')->label(__('Phone or e-mail'))->searchable(),
                TextColumn::make('name')->label(__('Name'))->placeholder('—'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->state(fn (InvestorInvite $record): string => $record->statusLabel())
                    ->color(fn (InvestorInvite $record): string => match (true) {
                        $record->used_at !== null => 'success',
                        $record->revoked_at !== null, $record->expires_at->isPast() => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('expires_at')->label(__('Works until'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state)),
                TextColumn::make('inviter.name')->label(__('Sent by'))->placeholder('—'),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(__('Cancel invite'))
                    ->color('danger')
                    ->visible(fn (InvestorInvite $record): bool => $record->isUsable())
                    ->requiresConfirmation()
                    ->action(function (InvestorInvite $record): void {
                        app(InviteService::class)->revoke($record, auth()->user());
                        Notification::make()->title(__('Invite cancelled'))->success()->send();
                    }),
            ])
            ->emptyStateHeading(__('No invites yet'))
            ->emptyStateDescription(__('Press "Invite an investor" to send a link by SMS or e-mail.'));
    }

    public static function getPages(): array
    {
        return ['index' => ListInvestorInvites::route('/')];
    }
}
