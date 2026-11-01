<?php

namespace App\Filament\Resources\Offers;

use App\Filament\Clusters\Storefront;
use App\Filament\Concerns\TrashFollowsDelete;
use App\Filament\Resources\Offers\Pages\CreateOffer;
use App\Filament\Resources\Offers\Pages\EditOffer;
use App\Filament\Resources\Offers\Pages\ListOffers;
use App\Filament\Resources\Offers\Pages\TrashedOffers;
use App\Filament\Resources\Offers\Schemas\OfferForm;
use App\Filament\Resources\Offers\Tables\OffersTable;
use App\Models\Offer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class OfferResource extends Resource
{
    use TrashFollowsDelete;

    protected static ?string $model = Offer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $cluster = Storefront::class;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return OfferForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OffersTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->canManageSettings() ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->canManageSettings() ?? false;
    }

    public static function canEdit($record): bool
    {
        return Auth::user()?->canManageSettings() ?? false;
    }

    public static function canDelete($record): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOffers::route('/'),
            'trash' => TrashedOffers::route('/trash'),
            'create' => CreateOffer::route('/create'),
            'edit' => EditOffer::route('/{record}/edit'),
        ];
    }

    /**
     * Admin-only preview — same "shows what isn't live yet" convention as
     * StorefrontSettingResource::previewUrl(), routed through
     * OfferController::showPreview() which has no published/status
     * restriction, so a draft or just-AI-generated landing page can be
     * checked before publishing.
     */
    public static function previewUrl(Offer $record): string
    {
        return route('storefront.preview.offers.show', [
            'company' => $record->company?->slug,
            'slug' => $record->slug,
        ]);
    }

    /**
     * The real, customer-facing URL — only meaningful once the offer is
     * Published and the company has a domain (OfferController::show() 404s
     * otherwise), so this is gated in the action's ->visible() below rather
     * than called unconditionally.
     */
    public static function publicUrl(Offer $record): string
    {
        return 'https://'.$record->company?->domain.'/offers/'.$record->slug;
    }
}
