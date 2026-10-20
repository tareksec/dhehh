<?php

namespace App\Ims\Todo;

use App\Filament\Ims\Office\Pages\PendingMoney;
use App\Filament\Ims\Office\Resources\CapitalReturns\CapitalReturnResource;
use App\Filament\Ims\Office\Resources\KycReviews\KycReviewResource;
use App\Filament\Ims\Office\Resources\ProfitProposals\ProfitProposalResource;
use App\Filament\Ims\Office\Resources\SupportThreads\SupportThreadResource;
use App\Filament\Resources\InvestorRequests\InvestorRequestResource;
use App\Filament\Resources\Investors\InvestorResource;
use App\Filament\Resources\ProjectSettlements\ProjectSettlementResource;
use App\Ims\Capital\MaturityQueue;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\InvestorBankAccount;
use App\Models\InvestorGroupMember;
use App\Models\InvestorRequest;
use App\Models\ProfitProposal;
use App\Models\SettlementPayout;
use App\Models\SupportThread;
use Throwable;

/**
 * The office's "what to do today" (IMS-V2/PRD.md FR-66): the same list on
 * the office home and in the morning digest. Each line says how many, in
 * plain words, and where to do it (an office page). Lines with nothing to
 * do are left out. For the company in CompanyContext.
 */
class OwnerTodo
{
    public function __construct(private MaturityQueue $maturities) {}

    /**
     * @return list<array{key: string, label: string, count: int, page: class-string|null, tone: string}>
     */
    public function items(): array
    {
        $lines = [
            ['proposals', fn (): int => ProfitProposal::query()->where('status', ProfitProposal::STATUS_PENDING)->count(),
                'Profit proposals to approve', ProfitProposalResource::class, 'warning'],
            ['payouts', fn (): int => SettlementPayout::query()->where('payment_status', 'pending')->whereHas('settlement', fn ($query) => $query->where('status', 'confirmed'))->count(),
                'Payouts still to send', ProjectSettlementResource::class, 'warning'],
            ['capital_returns', fn (): int => $this->maturities->dueWithin(7)->count(),
                'Capital to pay back (due within 7 days)', CapitalReturnResource::class, 'danger'],
            ['kyc', fn (): int => Investor::query()->where('kyc_status', Investor::KYC_PENDING)->count(),
                'KYC waiting for review', KycReviewResource::class, 'warning'],
            ['pending_money', fn (): int => Investment::query()->pending()->count() + InvestorGroupMember::query()->pending()->count(),
                'Money to confirm (investments waiting)', PendingMoney::class, 'warning'],
            ['bank_accounts', fn (): int => InvestorBankAccount::query()->where('status', InvestorBankAccount::STATUS_PENDING)->count(),
                'Bank accounts to approve', InvestorResource::class, 'info'],
            ['requests', fn (): int => InvestorRequest::query()->where('status', InvestorRequest::STATUS_PENDING)->where('type', '!=', InvestorRequest::TYPE_SUPPORT)->count(),
                'Investor requests to answer', InvestorRequestResource::class, 'info'],
            ['support', fn (): int => SupportThread::query()->where('status', SupportThread::STATUS_OPEN)->where('staff_unread', true)->count(),
                'Support messages to answer', SupportThreadResource::class, 'info'],
        ];

        $items = [];

        foreach ($lines as [$key, $count, $label, $page, $tone]) {
            try {
                $n = (int) $count();
            } catch (Throwable $exception) {
                // One broken count never hides the rest of the list.
                report($exception);

                continue;
            }

            if ($n > 0) {
                $items[] = ['key' => $key, 'label' => __($label), 'count' => $n, 'page' => class_exists($page) ? $page : null, 'tone' => $tone];
            }
        }

        return $items;
    }
}
