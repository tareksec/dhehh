<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\CompanyContext;
use App\Services\InvestorBusinessSwitchService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The central investor sign-in host (17_IMS_IN_PORTAL_PLAN.md §5): after the
 * session has started, opens the business the signed-in person last chose
 * (InvestorBusinessSwitchService::SESSION_KEY), so every page still renders
 * under one company's context. With no business chosen the context stays
 * closed, so the guard finds no account and the person is asked to sign in.
 *
 * Pages that belong to one business only (sign-up with a partner's link,
 * invites, password reset, staff links) are sent to the sign-in page here:
 * they are used on that business's own portal address.
 */
class ResolveCompanyFromCentralInvestorSession
{
    protected const BUSINESS_ONLY_PATHS = '#^investor/(register|password-reset|invite/|join/|staff-entry/|business-entry/)#';

    public function __construct(protected CompanyContext $context, protected InvestorBusinessSwitchService $switches) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Company::isImsCentralHost($request->getHost())) {
            return $next($request);
        }

        if (preg_match(self::BUSINESS_ONLY_PATHS, ltrim($request->path(), '/')) === 1) {
            return redirect()->to('/investor/login');
        }

        $company = $this->switches->centralCompany($request);
        $company ? $this->context->set($company) : $this->context->none();

        return $next($request);
    }
}
