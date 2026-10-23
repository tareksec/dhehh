<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Livewire\LogTable;
use App\Support\LogCatalog;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Livewire as SchemaLivewire;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Every log in one place (owner request 2026-10-23): one "Logs" menu entry and
 * one tab per log (SMS, audit, courier, Meta, deploy errors, mobile crashes),
 * the same way Integrations gathers the integrations. Each tab is that log's
 * own table (App\Livewire\LogTable) and is shown only to people who may open
 * that log; the list lives in App\Support\LogCatalog.
 */
class Logs extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.logs';

    public static function getNavigationLabel(): string
    {
        return __('Logs');
    }

    public function getTitle(): string
    {
        return __('Logs');
    }

    /** Part of Settings: only people who manage settings open it, and then only the logs each of them may see. */
    public static function canAccess(): bool
    {
        return (Auth::user()?->canManageSettings() ?? false) && LogCatalog::visible() !== [];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('logs')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs(collect(LogCatalog::visible())
                    ->map(fn (array $log, string $key): Tab => Tab::make($log['label'])
                        ->id($key)
                        ->icon($log['icon'])
                        ->schema([
                            // Lazy: a tab's table is read only when that tab is opened.
                            SchemaLivewire::make(LogTable::class, ['log' => $key])
                                ->key('logTable-'.$key)
                                ->lazy(),
                        ]))
                    ->values()
                    ->all()),
        ]);
    }
}
