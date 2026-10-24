<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Filament\Pages\Backups;
use App\Services\BackupHealth;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Are the backups happening? One card per check (hourly, daily, copy on Google Drive,
 * restore test), shown only to people who may manage backups.
 */
class BackupHealthWidget extends StatsOverviewWidget
{
    use GuardsWidgetRendering;

    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return Schema::hasTable('backup_runs')
            && (Auth::user()?->canManageBackups() ?? false);
    }

    protected function getHeading(): ?string
    {
        return __('Backup health');
    }

    /**
     * @return array<string, int>
     */
    protected function getColumns(): array
    {
        return [
            'default' => 2,
            'lg' => 4,
        ];
    }

    protected function getStats(): array
    {
        return array_map(function (array $check): Stat {
            [$value, $color, $icon] = match ($check['state']) {
                BackupHealth::OK => [__('OK'), 'success', Heroicon::OutlinedCheckCircle],
                BackupHealth::DANGER => [__('Overdue'), 'danger', Heroicon::OutlinedExclamationTriangle],
                BackupHealth::WARNING => [__('Attention'), 'warning', Heroicon::OutlinedExclamationCircle],
                default => [$check['optional'] ? __('Not set up') : __('Waiting'), 'gray', Heroicon::OutlinedClock],
            };

            return Stat::make($check['label'], $value)
                ->description($check['detail'])
                ->descriptionIcon($icon)
                ->color($color)
                ->url(Backups::getUrl());
        }, app(BackupHealth::class)->checks());
    }
}
