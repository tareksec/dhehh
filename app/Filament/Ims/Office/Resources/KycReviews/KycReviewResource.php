<?php

namespace App\Filament\Ims\Office\Resources\KycReviews;

use App\Filament\Concerns\HasGuardedNavigationBadge;
use App\Filament\Ims\Office\Resources\KycReviews\Pages\ListKycReviews;
use App\Filament\Ims\Office\Resources\KycReviews\Pages\ViewKycReview;
use App\Models\Investor;
use App\Support\ImsNumbers;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * KYC review in the IMS office (IMS-V2/DESIGN.md §8.2): who sent KYC, the
 * pictures side by side, what OCR read, the checklist, and approve / turn
 * down. Opens with the ones waiting for review.
 */
class KycReviewResource extends Resource
{
    use HasGuardedNavigationBadge;

    protected static ?string $model = Investor::class;

    protected static ?string $slug = 'kyc';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationLabel(): string
    {
        return __('KYC');
    }

    public static function getModelLabel(): string
    {
        return __('KYC');
    }

    public static function getPluralModelLabel(): string
    {
        return __('KYC');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->hasPermission('ims.kyc.review') || $user?->hasPermission('investments.manage')) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    protected static function navigationBadge(): ?string
    {
        $count = Investor::query()->where('kyc_status', Investor::KYC_PENDING)->count();

        return $count > 0 ? ImsNumbers::count($count) : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('kyc_submitted_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->wrap(),
                TextColumn::make('phone')->label(__('Phone'))->searchable(),
                TextColumn::make('kyc_status')->label(__('KYC'))->badge()
                    ->formatStateUsing(fn (?string $state): string => Investor::kycStatusLabel($state))
                    ->color(fn (?string $state): string => Investor::kycStatusColor($state)),
                TextColumn::make('kyc_submitted_at')->label(__('Sent on'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state))->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('kyc_status')->label(__('KYC'))
                    ->options(collect(Investor::KYC_STATUSES)->map(fn (string $label): string => __($label))->all())
                    ->default(Investor::KYC_PENDING),
            ])
            ->recordActions([ViewAction::make()->label(__('Open'))])
            ->emptyStateHeading(__('No KYC is waiting'))
            ->emptyStateDescription(__('When an investor sends their NID pictures and selfie, they show here.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKycReviews::route('/'),
            'view' => ViewKycReview::route('/{record}'),
        ];
    }
}
