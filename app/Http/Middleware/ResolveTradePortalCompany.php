<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\CompanyContext;
use App\Services\TradePortalCompanies;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on the Trade Portal after the session has started and before route
 * bindings: once a staff member is signed in, the portal opens the company
 * they chose (TradePortalCompanies::choose()), checked against the user on
 * every request, never from the session alone. When they may open none, the
 * context is closed and User::canAccessPanel() refuses them.
 */
class ResolveTradePortalCompany
{
    public function __construct(protected CompanyContext $context, protected TradePortalCompanies $companies) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();

        $user = Auth::guard('web')->user();

        if ($user instanceof User) {
            $company = $this->companies->choose($request, $user);
            $company ? $this->context->set($company) : $this->context->none();
        }

        return $next($request);
    }
}
