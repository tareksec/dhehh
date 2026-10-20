<?php

namespace App\Http\Controllers\Ims;

use App\Http\Controllers\Controller;
use App\Ims\Reports\StatementService;
use App\Models\InvestorPortalAccount;
use Filament\Facades\Filament;
use Symfony\Component\HttpFoundation\Response;

/** GET investor/ims/statements/{YYYY-MM}/pdf: the signed-in investor's own monthly statement (PRD FR-65). */
class ImsPortalStatementController extends Controller
{
    public function pdf(string $period, StatementService $statements): Response
    {
        $account = Filament::auth()->user();
        abort_unless($account instanceof InvestorPortalAccount && $account->isInvestor(), 404);
        abort_if($period > now()->format('Y-m'), 404);

        return response($statements->pdf($account, $period), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="statement-'.$period.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
