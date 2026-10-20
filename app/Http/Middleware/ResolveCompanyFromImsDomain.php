<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every request to the IMS office panel (IMS-V2/ARCHITECTURE.md §3).
 * The office belongs to the company whose IMS domain this host is
 * (Company::forImsHost()); it goes in CompanyContext so CompanyScope keeps
 * every query on that company, and User::canAccessPanel() refuses staff who
 * do not work for it. Any other host is a 404.
 */
class ResolveCompanyFromImsDomain
{
    public function __construct(protected CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $company = Company::forImsHost($request->getHost());

        if (! $company) {
            abort(404);
        }

        $this->context->set($company);
        $request->attributes->set('ims_company', $company);

        return $next($request);
    }
}
