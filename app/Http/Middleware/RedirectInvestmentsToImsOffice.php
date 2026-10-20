<?php

namespace App\Http\Middleware;

use App\Services\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IMS v2 (IMS-V2/PLAN.md Q2, PRD FR-82): once a company runs IMS on its own
 * domain, the ERP admin's investor pages live in the IMS office. An old
 * /admin/investments/... address of that company goes to the same page in
 * the office. Companies without IMS keep the ERP pages as they are.
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

            return redirect()->away($company->imsUrl('office/'.$rest).($query ? '?'.$query : ''));
        }

        return $next($request);
    }
}
