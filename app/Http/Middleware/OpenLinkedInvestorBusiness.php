<?php

namespace App\Http\Middleware;

use App\Models\InvestorPortalAccount;
use App\Services\InvestorBusinessSwitchService;
use App\Services\InvestorPortfolio;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Investor portal, after sign-in (18_IMS_ALL_COMPANIES_PLAN.md §4): a page
 * opened with ?business=<id> (a project or a request of another company in
 * the person's combined lists) first opens the person's own account in that
 * company - the same checked switch as the business switcher - then the same
 * page without the marker. A company the person has no active account in is
 * ignored; the page opens in the current company.
 */
class OpenLinkedInvestorBusiness
{
    public function __construct(protected InvestorBusinessSwitchService $switches) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = (int) $request->query(InvestorPortfolio::QUERY_KEY, 0);

        if ($id === 0 || ! $request->isMethod('GET')) {
            return $next($request);
        }

        $account = Filament::auth()->user();
        $clean = $request->fullUrlWithoutQuery([InvestorPortfolio::QUERY_KEY]);

        if (! $account instanceof InvestorPortalAccount || $id === (int) $account->company_id) {
            return redirect()->to($clean);
        }

        $target = $this->switches->switchTo($account, $id, $request);

        if ($target === null) {
            return redirect()->to($clean);
        }

        // Same address (the shared IMS domain): the session now belongs to that company, so open the page itself.
        return redirect()->to($this->switches->switchesInSession($request) ? $clean : $target);
    }
}
