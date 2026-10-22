<?php

namespace App\Filament\Ims\Office\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Ims\Capital\MaturityQueue;
use App\Support\ImsNumbers;
use Filament\Widgets\Widget;

/** Capital that has matured or matures in the next 30 days (on Capital returns). */
class MaturingCapital extends Widget
{
    use GuardsWidgetRendering;

    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.ims.office.widgets.maturing-capital';

    /** @return list<array{name: string, place: string, amount: string, date: string, overdue: bool, soon: bool}> */
    public function rows(): array
    {
        return app(MaturityQueue::class)->dueWithin(30)->map(fn (array $row): array => [
            'name' => (string) $row['record']->investor?->name,
            'place' => (string) ($row['record']->group?->name ?? $row['record']->project?->name ?? ''),
            'amount' => ImsNumbers::money($row['outstanding']),
            'date' => ImsNumbers::date($row['maturity_date']),
            'overdue' => $row['overdue'],
            'soon' => $row['maturity_date']->lte(now()->addDays(7)),
        ])->all();
    }
}
