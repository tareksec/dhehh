<?php

namespace App\Filament\Ims\Office\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Ims\Todo\OwnerTodo;
use Filament\Widgets\Widget;

/** "What to do today" at the top of the office home (IMS-V2/DESIGN.md §2, §8.2). */
class OwnerTodoList extends Widget
{
    use GuardsWidgetRendering;

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.ims.office.widgets.owner-todo-list';

    /** @return list<array{key: string, label: string, count: int, url: string|null, tone: string}> */
    public function items(): array
    {
        return array_map(fn (array $item): array => [
            ...$item,
            'url' => $item['page'] !== null && $item['page']::canAccess() ? $item['page']::getUrl(panel: 'office') : null,
        ], app(OwnerTodo::class)->items());
    }
}
