<?php

namespace App\Livewire;

use App\Support\LogCatalog;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\ViewAction;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One tab of the Logs page (App\Filament\Pages\Logs): the table of the log's
 * own resource, embedded through Filament\Schemas\Components\Livewire (same
 * pattern as MetaEventLogTable). The resource's columns, filters and actions
 * are reused unchanged; the model's company scope keeps every query to the
 * company in use. A resource's "View" button, which needs the resource's own
 * page, becomes a plain link to that page.
 */
class LogTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public string $log = '';

    public function mount(string $log): void
    {
        $this->log = $log;

        $this->resourceClass();
    }

    /** @return class-string */
    protected function resourceClass(): string
    {
        $resource = LogCatalog::resource($this->log);

        abort_unless($resource !== null, 403);

        return $resource;
    }

    public function table(Table $table): Table
    {
        $resource = $this->resourceClass();
        $table = $resource::table($table)
            ->query($resource::getModel()::query())
            ->emptyStateHeading(__('Nothing logged yet'));

        if (! array_key_exists('view', $resource::getPages())) {
            return $table;
        }

        return $table->recordActions(collect($table->getRecordActions())
            ->map(fn ($action) => $action instanceof ViewAction
                ? Action::make('open')
                    ->label(__('View'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (Model $record): string => $resource::getUrl('view', ['record' => $record], panel: 'admin'))
                : $action)
            ->all());
    }

    public function render(): View
    {
        $resource = $this->resourceClass();

        return view('livewire.log-table', [
            'notice' => method_exists($resource, 'logNotice') ? $resource::logNotice() : null,
        ]);
    }
}
