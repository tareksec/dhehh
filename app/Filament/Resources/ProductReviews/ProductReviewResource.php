<?php

namespace App\Filament\Resources\ProductReviews;

use App\Filament\Clusters\Storefront;
use App\Filament\Concerns\TrashFollowsDelete;
use App\Filament\Resources\ProductReviews\Pages\ListProductReviews;
use App\Filament\Resources\ProductReviews\Pages\TrashedProductReviews;
use App\Models\ProductReview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ProductReviewResource extends Resource
{
    use TrashFollowsDelete;

    protected static ?string $model = ProductReview::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?string $cluster = Storefront::class;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Reviews';

    protected static ?string $recordTitleAttribute = 'customer_name';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('rating')
                    ->formatStateUsing(fn (int $state): string => str_repeat('★', $state).str_repeat('☆', 5 - $state))
                    ->sortable(),
                TextColumn::make('comment')
                    ->limit(60)
                    ->placeholder('-')
                    ->wrap(),
                TextColumn::make('order.order_number')
                    ->label('Order')
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ProductReview::STATUSES[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        ProductReview::STATUS_APPROVED => 'success',
                        ProductReview::STATUS_REJECTED => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ProductReview::STATUSES),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (ProductReview $record): bool => $record->status !== ProductReview::STATUS_APPROVED)
                    ->action(fn (ProductReview $record) => $record->update(['status' => ProductReview::STATUS_APPROVED])),
                Action::make('reject')
                    ->label('Reject')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (ProductReview $record): bool => $record->status !== ProductReview::STATUS_REJECTED)
                    ->requiresConfirmation()
                    ->action(fn (ProductReview $record) => $record->update(['status' => ProductReview::STATUS_REJECTED])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approveSelected')
                        ->label('Approve selected')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->action(function (Collection $records): void {
                            $records->each->update(['status' => ProductReview::STATUS_APPROVED]);

                            Notification::make()->title('Reviews approved')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('rejectSelected')
                        ->label('Reject selected')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $records->each->update(['status' => ProductReview::STATUS_REJECTED]);

                            Notification::make()->title('Reviews rejected')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->canManageSettings() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    /** Several reviews share a customer name, so the Delete prompt names the review by customer and product. */
    public static function hasRecordTitle(): bool
    {
        return true;
    }

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (! $record instanceof ProductReview) {
            return null;
        }

        $product = $record->product?->name ?? __('Review').' #'.$record->getKey();

        return filled($record->customer_name) ? $record->customer_name.' — '.$product : $product;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductReviews::route('/'),
            'trash' => TrashedProductReviews::route('/trash'),
        ];
    }
}
