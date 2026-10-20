<?php

namespace App\Filament\Investor\Concerns;

use App\Services\InvestorPortfolio;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A portal list built from every company the person invests in
 * (18_IMS_ALL_COMPANIES_PLAN.md §4, phase E): the rows come from
 * InvestorPortfolio, which reads each company under its own context, and are
 * shown as an array table with one Project filter (empty = all).
 */
trait CombinedPortalTable
{
    protected function projectFilter(): SelectFilter
    {
        return SelectFilter::make('project')
            ->label(__('Project'))
            ->placeholder(__('All projects'))
            ->options(fn (): array => app(InvestorPortfolio::class)->projectOptions($this->account()));
    }

    /** The Project filter's value, or null for all projects. */
    protected function chosenProject(?array $filters): ?string
    {
        $value = $filters['project']['value'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function pageOfRows(array $rows, int|string $page, int|string $recordsPerPage): LengthAwarePaginator
    {
        $perPage = $recordsPerPage === 'all' ? max(count($rows), 1) : max((int) $recordsPerPage, 1);
        $page = max((int) $page, 1);

        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(string) $row['key']] = $row;
        }

        return new LengthAwarePaginator(array_slice($keyed, ($page - 1) * $perPage, $perPage, true), count($keyed), $perPage, $page);
    }

    /** Whether to name the company on each row: only when the person invests in more than one. */
    public function showsCompanies(): bool
    {
        return app(InvestorPortfolio::class)->spansCompanies($this->account());
    }
}
