<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Crm;
use App\Models\Conversation;
use App\Services\Crm\AiInboxReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * How much the AI handles and how fast the team answers what it hands over
 * (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.4, phase 3).
 */
class AiInboxReport extends Page
{
    protected static ?string $cluster = Crm::class;

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected string $view = 'filament.pages.ai-inbox-report';

    public string $period = '7';

    public static function getNavigationLabel(): string
    {
        return __('AI Inbox Report');
    }

    public function getTitle(): string
    {
        return __('AI Inbox Report');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('crm.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function setPeriod(string $period): void
    {
        $this->period = array_key_exists($period, AiInboxReportService::PERIODS) ? $period : '7';
    }

    public function report(): array
    {
        return app(AiInboxReportService::class)->summary($this->period);
    }

    public function periods(): array
    {
        return collect(AiInboxReportService::PERIODS)->map(fn (string $label): string => __($label))->all();
    }

    public function reasonLabel(string $reason): string
    {
        return __(Conversation::HANDOVER_REASONS[$reason] ?? $reason);
    }

    public function minutes(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        return $minutes >= 60
            ? __(':hours h :minutes min', ['hours' => intdiv($minutes, 60), 'minutes' => $minutes % 60])
            : __(':minutes min', ['minutes' => $minutes]);
    }
}
