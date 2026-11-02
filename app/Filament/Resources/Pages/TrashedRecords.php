<?php

namespace App\Filament\Resources\Pages;

use App\Support\InfoTip;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The Trash page of a resource whose model uses SoftDeletes (owner request
 * 2026-11-01): Delete asks for the record's name, then the record lands here,
 * where it can be restored or deleted permanently (which asks for the name
 * again, see App\Support\TypeToConfirmDelete).
 *
 * It shows the resource's own table (same columns and filters) limited to the
 * trashed rows. To give a resource a Trash:
 *
 *  1. the model uses `SoftDeletes`;
 *  2. a page `class TrashedThings extends TrashedRecords { protected static string $resource = ThingResource::class; }`;
 *  3. `'trash' => TrashedThings::route('/trash')` in the resource's `getPages()`;
 *  4. `TrashedRecords::linkAction(ThingResource::class)` in the list page's header actions;
 *  5. the resource's `canRestore*()` / `canForceDelete*()` say who may use it.
 *
 * A model whose delete or restore moves money or stock (vouchers, orders) keeps
 * its own service and Trash page instead of this generic one.
 */
abstract class TrashedRecords extends ListRecords
{
    /** The "Trash (n)" button for the list page's header. */
    public static function linkAction(string $resource): Action
    {
        return Action::make('trash')
            ->label(fn (): string => __('Trash (:count)', ['count' => $resource::getEloquentQuery()->onlyTrashed()->count()]))
            ->icon(Heroicon::OutlinedTrash)
            ->color('gray')
            ->visible(fn (): bool => $resource::hasPage('trash') && $resource::canViewAny() && ($resource::canRestoreAny() || $resource::canForceDeleteAny()))
            ->url(fn (): string => $resource::getUrl('trash'));
    }

    public static function canAccess(array $parameters = []): bool
    {
        $resource = static::getResource();

        return $resource::canAccess() && $resource::canViewAny() && ($resource::canRestoreAny() || $resource::canForceDeleteAny());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        parent::mount();
    }

    public function getTitle(): string|Htmlable
    {
        return __(':label trash', ['label' => static::getResource()::getTitleCasePluralModelLabel()]);
    }

    public function getBreadcrumb(): ?string
    {
        return __('Trash');
    }

    public function getHeading(): string|Htmlable
    {
        return InfoTip::heading(
            (string) $this->getTitle(),
            __('Records moved to trash are kept here. Restore one to put it back in the list, or delete it permanently. A permanent delete cannot be undone.'),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('Back'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => static::getResource()::getUrl('index')),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    protected function getFooterWidgets(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        $resource = static::getResource();

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->onlyTrashed())
            ->pushColumns([
                TextColumn::make('deleted_at')->label(__('Moved to trash at'))->dateTime()->sortable(),
            ])
            ->defaultSort('deleted_at', 'desc')
            // Drag-to-reorder belongs to the live list, not to the Trash.
            ->reorderable(condition: false)
            ->recordUrl(null)
            ->recordAction(null)
            ->headerActions([])
            ->recordActions([
                RestoreAction::make()
                    ->authorize(fn (Model $record): bool => $resource::canRestore($record))
                    ->using(fn (Model $record, RestoreAction $action): bool => $this->restoreRecord($record, $action)),
                ForceDeleteAction::make()
                    ->label(__('Delete permanently'))
                    ->authorize(fn (Model $record): bool => $resource::canForceDelete($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    RestoreBulkAction::make()->authorizeIndividualRecords(fn (Model $record): bool => $resource::canRestore($record)),
                    ForceDeleteBulkAction::make()
                        ->label(__('Delete permanently'))
                        ->authorizeIndividualRecords(fn (Model $record): bool => $resource::canForceDelete($record)),
                ]),
            ])
            ->emptyStateHeading(__('Trash is empty'))
            ->emptyStateIcon(Heroicon::OutlinedTrash);
    }

    /** A restore that a model rule refuses (for example "this would make the balance negative") says why instead of failing silently. */
    private function restoreRecord(Model $record, RestoreAction $action): bool
    {
        try {
            return (bool) $record->restore();
        } catch (ValidationException $exception) {
            $action->failureNotificationTitle(collect($exception->errors())->flatten()->first() ?? __('Could not restore this record.'));

            return false;
        }
    }
}
