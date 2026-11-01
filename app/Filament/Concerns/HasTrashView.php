<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A Trash for a relation manager's table (owner request 2026-11-01), for rows
 * that belong to a record and have no page of their own (an order's payments).
 * A "Trash (n)" button in the table header switches the table between the
 * list and the trashed rows; trashed rows get Restore and Delete permanently
 * (which asks for the name, see App\Support\TypeToConfirmDelete).
 *
 * For a resource's own list use App\Filament\Resources\Pages\TrashedRecords.
 *
 * In the relation manager (the model uses SoftDeletes):
 *
 *  - `use HasTrashView;`
 *  - `->modifyQueryUsing(fn (Builder $query): Builder => $this->trashQuery($query))`
 *  - `$this->trashToggleAction()` first in `->headerActions([...])`, and hide the
 *    create action while `$this->showTrash`
 *  - `...$this->trashRecordActions()` in `->recordActions([...])`, and hide edit
 *    while `$this->showTrash`
 *  - `->recordTitle(...)` when a row has no name-like column
 */
trait HasTrashView
{
    /** true while the table shows the Trash instead of the list */
    public bool $showTrash = false;

    protected function trashQuery(Builder $query): Builder
    {
        return $this->showTrash ? $query->onlyTrashed() : $query;
    }

    protected function trashToggleAction(): Action
    {
        return Action::make('trash')
            ->label(fn (): string => $this->showTrash
                ? __('Back to the list')
                : __('Trash (:count)', ['count' => $this->getRelationship()->onlyTrashed()->count()]))
            ->icon(fn (): Heroicon => $this->showTrash ? Heroicon::OutlinedArrowLeft : Heroicon::OutlinedTrash)
            ->color('gray')
            ->visible(fn (): bool => ! $this->isReadOnly() && ($this->canRestoreAny() || $this->canForceDeleteAny()))
            ->action(function (): void {
                $this->showTrash = ! $this->showTrash;
                $this->resetPage();
                $this->flushCachedTableRecords();
            });
    }

    /** @return list<Action> */
    protected function trashRecordActions(): array
    {
        return [
            RestoreAction::make()
                ->authorize(fn (Model $record): bool => $this->canRestore($record)),
            ForceDeleteAction::make()
                ->label(__('Delete permanently'))
                ->authorize(fn (Model $record): bool => $this->canForceDelete($record)),
        ];
    }
}
