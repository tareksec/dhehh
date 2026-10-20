<?php

namespace App\Http\Middleware;

use App\Services\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IMS v2 (IMS-V2/PLAN.md Q2, PRD FR-82): once a company runs IMS (on its own
 * domain or, since 18_IMS_ALL_COMPANIES_PLAN.md, on the group's), the ERP
 * admin's investor pages live in the IMS office. An old /admin/investments/...
 * address of that company goes to the same page in the office, with that
 * company open. Companies without IMS keep the ERP pages as they are.
 */
class RedirectInvestmentsToImsOffice
{
    public function __construct(protected CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $company = $this->context->company();
        $path = ltrim($request->path(), '/');

        // The IMS domain itself is switched on and off here, so this page stays in the ERP.
        $stays = str_starts_with($path, 'admin/investments/investor-portal-settings');

        if (! $stays && $request->isMethod('GET') && $company?->imsIsLive() && ($path === 'admin/investments' || str_starts_with($path, 'admin/investments/'))) {
            $rest = substr($path, strlen('admin/'));
            $query = $request->getQueryString();

            // The link opens this company in the office (18_IMS_ALL_COMPANIES_PLAN.md §2: every company shares the IMS domain).
            return redirect()->away($company->imsOfficeUrl($rest).($query ? '&'.$query : ''));
        }

        return $next($request);
    }
}
