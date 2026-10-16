<?php

namespace App\Services\Sync;

use App\Models\Investment;
use App\Models\InvestmentDocument;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\InvestorBankAccount;
use App\Models\InvestorGroup;
use App\Models\SettlementPayout;
use App\Services\InvestorPortalCards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The investors' side of the IMS link (owner decisions 2026-10-15): who the
 * investors are, their groups and investments, the projects with how far each
 * has run (a percentage), and every payout with its status and screenshot.
 *
 * Read only, same paging and rules as ImsSyncFeed, but behind its own
 * "Investor sync" key because it carries personal data the business feed never
 * does (NID, phone, bank accounts — the owner chose to share them). It never
 * carries a customer or a supplier.
 */
class InvestorSyncFeed extends ImsSyncFeed
{
    protected function definitions(): array
    {
        $today = Carbon::today();
        $cards = app(InvestorPortalCards::class);

        return [
            'investors' => [
                'query' => fn (): Builder => Investor::query(),
                'with' => ['bankAccounts', 'groupMemberships.group:id,group_code,name'],
                'map' => fn (Investor $i): array => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'display_name' => $i->display_name,
                    'phone' => $i->phone,
                    'nid_number' => $i->nid_number,
                    'groups' => $i->groupMemberships->map(fn ($member): array => [
                        'group_id' => $member->group_id,
                        'group_code' => $member->group?->group_code,
                        'group_name' => $member->group?->name,
                        'amount' => $this->money($member->amount),
                        // 'active' or 'withdrawn'.
                        'status' => $member->status,
                    ])->values()->all(),
                    // Only accounts the company approved: those are the ones payouts go to.
                    'bank_accounts' => $i->bankAccounts
                        ->where('status', InvestorBankAccount::STATUS_APPROVED)
                        ->map(fn (InvestorBankAccount $a): array => [
                            'id' => $a->id,
                            // 'bank' or 'mfs'.
                            'type' => $a->type,
                            'bank_name' => $a->bank_name,
                            'branch_name' => $a->branch_name,
                            'routing_number' => $a->routing_number,
                            'account_name' => $a->account_name,
                            'account_number' => $a->account_number,
                            'mfs_provider' => $a->mfs_provider,
                            'mfs_number' => $a->mfs_number,
                            'is_default' => (bool) $a->is_default,
                        ])->values()->all(),
                    'created_at' => $this->time($i->created_at), 'updated_at' => $this->time($i->updated_at),
                ],
            ],
            'groups' => [
                'query' => fn (): Builder => InvestorGroup::query(),
                'status' => 'status',
                'map' => fn (InvestorGroup $g): array => [
                    'id' => $g->id,
                    'group_code' => $g->group_code,
                    'name' => $g->name,
                    // 'open', 'running' or 'closed'.
                    'status' => $g->status,
                    'running_since' => $this->date($g->running_since),
                    'cycle_months' => $g->cycle_months,
                    'created_at' => $this->time($g->created_at), 'updated_at' => $this->time($g->updated_at),
                ],
            ],
            // A project is one trade cycle of a group. Only how far it has run is shared (owner: "just the percentage").
            'projects' => [
                'query' => fn (): Builder => InvestmentProject::query(),
                'with' => ['group:id,group_code,name'],
                'status' => 'status',
                'map' => fn (InvestmentProject $p): array => [
                    'id' => $p->id,
                    'project_code' => $p->project_code,
                    'name' => $p->name,
                    'group_id' => $p->group_id,
                    'group_code' => $p->group?->group_code,
                    'sequence_in_group' => $p->sequence_in_group,
                    // 'open', 'running', 'closed' or 'settled'.
                    'status' => $p->status,
                    'start_date' => $this->date($p->start_date),
                    'end_date' => $this->date($p->end_date),
                    // 0 to 100: how far through its dates the project is; 100 once its cycle is over.
                    'progress_percent' => $cards->progress(
                        $p,
                        in_array($p->status, ['closed', 'settled'], true) ? InvestorPortalCards::COMPLETED : InvestorPortalCards::RUNNING,
                        \Carbon\CarbonImmutable::parse($today->toDateString()),
                    ),
                    'created_at' => $this->time($p->created_at), 'updated_at' => $this->time($p->updated_at),
                ],
            ],
            'investments' => [
                'query' => fn (): Builder => Investment::query(),
                'status' => 'status',
                'date' => 'invested_at',
                'map' => fn (Investment $inv): array => [
                    'id' => $inv->id,
                    'investor_id' => $inv->investor_id,
                    'project_id' => $inv->project_id,
                    'group_member_id' => $inv->group_member_id,
                    'amount' => $this->money($inv->amount),
                    // 'active' or 'withdrawn'.
                    'status' => $inv->status,
                    'invested_at' => $this->date($inv->invested_at),
                    'withdrawn_at' => $this->date($inv->withdrawn_at),
                    'created_at' => $this->time($inv->created_at), 'updated_at' => $this->time($inv->updated_at),
                ],
            ],
            'payouts' => [
                'query' => fn (): Builder => SettlementPayout::query(),
                'with' => ['settlement:id,project_id,status', 'documents'],
                'status' => 'payment_status',
                'date' => 'paid_at',
                'map' => function (SettlementPayout $p): array {
                    $proof = $p->documents
                        ->where('category', InvestmentDocument::CATEGORY_PAYMENT_PROOF)
                        ->sortByDesc('id')
                        ->first();

                    return [
                        'id' => $p->id,
                        'investor_id' => $p->investor_id,
                        'settlement_id' => $p->settlement_id,
                        'project_id' => $p->settlement?->project_id,
                        'investment_amount' => $this->money($p->investment_amount),
                        'principal_returned_amount' => $this->money($p->principalReturned()),
                        'profit_share_amount' => $this->money($p->profit_share_amount),
                        'total_payout' => $this->money($p->total_payout),
                        // 'pending' or 'paid'.
                        'payment_status' => $p->payment_status,
                        'paid_at' => $this->date($p->paid_at),
                        'payment_method' => $p->payment_method,
                        'payment_reference' => $p->payment_reference,
                        'recipient_name' => $p->recipient_name,
                        'recipient_bank_name' => $p->recipient_bank_name,
                        'recipient_branch' => $p->recipient_branch,
                        'recipient_account_number' => $p->recipient_account_number,
                        // The screenshot: fetch the file from `download_path` with the same key.
                        'payment_proof' => $proof ? [
                            'document_id' => $proof->id,
                            'uploaded_at' => $this->time($proof->created_at),
                            'download_path' => "/api/v1/sync/investor/payouts/{$p->id}/proof",
                        ] : null,
                        'created_at' => $this->time($p->created_at), 'updated_at' => $this->time($p->updated_at),
                    ];
                },
            ],
        ];
    }
}
