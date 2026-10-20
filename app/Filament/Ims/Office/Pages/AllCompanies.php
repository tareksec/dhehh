<?php

namespace App\Filament\Ims\Office\Pages;

use App\Filament\Resources\InvestmentProjects\InvestmentProjectResource;
use App\Ims\Office\AllCompaniesFigures;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanySettingsService;
use App\Services\ImsOfficeCompanies;
use App\Support\ImsNumbers;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

/**
 * "All companies" in the IMS office (18_IMS_ALL_COMPANIES_PLAN.md §3.3): big
 * cards in plain words for every company the person may open — the money
 * (per currency), what is waiting for a decision, profit to pay, one card per
 * company, every running project and the new investors this month. Each line
 * says its company and opens the existing office page with that company open
 * (?office_company=<id>), where the usual buttons and permissions apply; this
 * page decides nothing itself.
 */
class AllCompanies extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $slug = 'all-companies';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.ims.office.pages.all-companies';

    /** @var list<array<string, mixed>>|null */
    protected ?array $rows = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ImsOfficeCompanies::class)->for($user, static::host())->count() > 1;
    }

    public function getTitle(): string
    {
        return __('All companies');
    }

    public function getHeader(): ?View
    {
        return view('filament.ims.welcome-header', [
            'name' => (string) (auth()->user()?->name ?? ''),
            'greeting' => __('All companies'),
            'date' => ImsNumbers::date(CarbonImmutable::now()),
            'action' => null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        if ($this->rows === null) {
            $user = auth()->user();
            $companies = $user instanceof User ? app(ImsOfficeCompanies::class)->for($user, static::host()) : collect();
            $this->rows = app(AllCompaniesFigures::class)->perCompany($companies);
        }

        return $this->rows;
    }

    /** @return array<string, array<string, float|int>> */
    public function totals(): array
    {
        return app(AllCompaniesFigures::class)->totals($this->rows());
    }

    /** @return list<array{company: Company, key: string, label: string, count: int, url: string|null, tone: string}> */
    public function waiting(): array
    {
        $items = [];

        foreach ($this->rows() as $row) {
            foreach ($row['waiting'] as $item) {
                $items[] = [
                    ...$item,
                    'company' => $row['company'],
                    'url' => $item['page'] !== null && $item['page']::canAccess() ? $this->open($item['page']::getUrl(panel: 'office'), $row['company']) : null,
                ];
            }
        }

        return $items;
    }

    /** @return list<array{company: Company, project: \App\Models\InvestmentProject, invested: float, currency: string, url: string|null}> */
    public function projects(): array
    {
        $canOpen = InvestmentProjectResource::canAccess();
        $projects = [];

        foreach ($this->rows() as $row) {
            foreach ($row['projects'] as $entry) {
                $projects[] = [
                    ...$entry,
                    'company' => $row['company'],
                    'currency' => $row['currency'],
                    'url' => $canOpen ? $this->open(InvestmentProjectResource::getUrl('view', ['record' => $entry['project']], panel: 'office'), $row['company']) : null,
                ];
            }
        }

        return $projects;
    }

    /** The office address of $url with $company open. */
    public function open(string $url, Company $company): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').ImsOfficeCompanies::QUERY_KEY.'='.$company->getKey();
    }

    public function companyHome(Company $company): string
    {
        return $this->open(Home::getUrl(panel: 'office'), $company);
    }

    public function companyName(Company $company): string
    {
        return app(CompanySettingsService::class)->name($company);
    }

    protected static function host(): ?string
    {
        return Company::forImsHost(request()->getHost())?->ims_domain;
    }
}
