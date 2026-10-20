<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\ImsOfficeCompanies;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on the IMS office after the session has started
 * (18_IMS_ALL_COMPANIES_PLAN.md §3.2). Every company is on the one IMS
 * domain, so once a staff member is signed in, the office opens the company
 * they chose (ImsOfficeCompanies::choose()), checked against the user on
 * every request, never from the session alone. When they may open none, the
 * context is closed and User::canAccessPanel() refuses them.
 *
 * Before anyone is signed in (the sign-in page) the IMS domain's own company
 * stays open, as ResolveCompanyFromImsDomain set it.
 */
class ResolveImsOfficeCompany
{
    public function __construct(protected CompanyContext $context, protected ImsOfficeCompanies $companies) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        $hostCompany = Company::forImsHost($request->getHost());

        if ($user instanceof User && $hostCompany) {
            $company = $this->companies->choose($request, $user, $hostCompany);
            $company ? $this->context->set($company) : $this->context->none();
        }

        return $next($request);
    }
}
