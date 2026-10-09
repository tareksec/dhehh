<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\ChannelPartners\ChannelPartnerResource;
use App\Filament\Resources\Investors\InvestorResource;
use App\Http\Controllers\Controller;
use App\Models\ChannelPartner;
use App\Models\Investor;
use App\Services\CompanyContext;
use App\Services\InvestorPortalStaffEntryService;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Open investor portal" on an investor's or channel partner's page: the
 * button is a plain link that opens in a new tab and lands here, on the
 * dashboard host. This checks the staff member, then sends the tab on to the
 * portal host with a one-time token (InvestorPortalStaffEntryService). If it
 * cannot, the tab shows the person's page again with the reason.
 */
class InvestorPortalOpenController extends Controller
{
    public function investor(Request $request, int $investor, CompanyContext $context, InvestorPortalStaffEntryService $entries): RedirectResponse
    {
        $record = Investor::withoutGlobalScopes()->findOrFail($investor);

        return $this->open($request, $record, $context, $entries, fn (): string => InvestorResource::getUrl('view', ['record' => $record]));
    }

    public function partner(Request $request, int $partner, CompanyContext $context, InvestorPortalStaffEntryService $entries): RedirectResponse
    {
        $record = ChannelPartner::withoutGlobalScopes()->findOrFail($partner);

        return $this->open($request, $record, $context, $entries, fn (): string => ChannelPartnerResource::getUrl('view', ['record' => $record]));
    }

    protected function open(Request $request, Investor|ChannelPartner $holder, CompanyContext $context, InvestorPortalStaffEntryService $entries, callable $backUrl): RedirectResponse
    {
        $user = $request->user();
        $company = $context->company();

        if (! $company && $context->isAllCompanies() && $user?->isSuperAdmin()) {
            $company = $holder->company()->first();
        }

        // Same boundary as the document downloads: the person must belong to the company the staff member is working in.
        abort_unless(
            $user?->is_active
            && $company
            && (int) $holder->company_id === (int) $company->getKey()
            && $user->canAccessCompany((int) $company->getKey()),
            404,
        );
        abort_unless($entries->mayOpen($user), 403);

        try {
            $url = $entries->issue($holder, $user);
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title(__('Could not open the portal'))->body(collect($exception->errors())->flatten()->first())->send();

            return redirect()->to($backUrl())->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        // The token is in the address: keep the redirect out of caches.
        return redirect()->away($url)->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
