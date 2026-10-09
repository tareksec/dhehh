<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Models\ChatOrderLink;
use App\Models\CrmAiRun;
use App\Models\CrmSalesFollowUp;
use App\Models\Lead;
use App\Models\Order;
use App\Services\CompanyContext;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CrmSalesMetrics extends StatsOverviewWidget
{
    use GuardsWidgetRendering;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Sales automation — last 30 days';

    public static function canView(): bool
    {
        return auth()->user()?->hasPermission('crm.view') ?? false;
    }

    protected function getStats(): array
    {
        if (! app(CompanyContext::class)->hasCompany()) {
            return [Stat::make('Sales automation', 'Select a company')->description('Revenue is reported in that company’s currency')];
        }
        $runs = CrmAiRun::query()->where('created_at', '>=', now()->subDays(30));
        $links = ChatOrderLink::query()->where('created_at', '>=', now()->subDays(30));
        $orders = Order::query()->whereIn('source', [Order::SOURCE_CHAT, Order::SOURCE_CRM])->where('status', Order::STATUS_COMPLETED)->where('created_at', '>=', now()->subDays(30));
        $completed = (clone $orders)->count();
        $cost = (float) (clone $runs)->where('status', '!=', 'running')->sum('estimated_cost_usd');
        $costKnown = ! (clone $runs)->whereNull('estimated_cost_usd')->where('input_tokens', '>', 0)->exists();

        return [
            Stat::make('Qualified leads (current)', Lead::query()->where('qualification_score', '>=', 30)->count()),
            Stat::make('Unassigned hot leads', Lead::query()->where('temperature', 'hot')->whereNull('assigned_to')->whereNotIn('status', ['won', 'lost'])->count()),
            Stat::make('Checkout links opened / created', (clone $links)->whereNotNull('opened_at')->count().' / '.(clone $links)->count()),
            Stat::make('Checkout links converted', (clone $links)->whereNotNull('converted_order_id')->count())->description('Draft orders included; revenue requires completed orders'),
            Stat::make('Completed CRM/chat orders', $completed),
            Stat::make('Completed order revenue', number_format((float) (clone $orders)->sum('total_amount'), 2))->description('Company currency; excludes draft orders'),
            Stat::make('AI estimated cost (USD)', $costKnown ? number_format($cost, 4) : 'Rates incomplete'),
            Stat::make('AI cost / completed order (USD)', $completed && $costKnown ? number_format($cost / $completed, 4) : '—')->description('Period ratio, not causal attribution'),
            Stat::make('AI processing latency', number_format((float) (clone $runs)->where('input_tokens', '>', 0)->avg('duration_ms') / 1000, 1).' s'),
            Stat::make('Inbound to AI reply', number_format((float) (clone $runs)->where('status', 'sent')->avg('response_seconds'), 1).' s'),
            Stat::make('Human handoffs', (clone $runs)->where('status', 'handed_off')->count()),
            Stat::make('Handoffs answered by staff', (clone $runs)->whereNotNull('resolved_at')->count()),
            Stat::make('Follow-ups sent', CrmSalesFollowUp::query()->where('sent_at', '>=', now()->subDays(30))->count()),
            Stat::make('Follow-ups needing review', CrmSalesFollowUp::query()->whereIn('status', ['review', 'unknown', 'sending'])->count()),
        ];
    }
}
