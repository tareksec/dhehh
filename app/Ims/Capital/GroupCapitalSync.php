<?php

namespace App\Ims\Capital;

use App\Models\InvestmentProject;
use App\Models\InvestorGroupMember;

/**
 * A group runs its next project before the last one is settled, so the next
 * project's copy of each member is made with the capital known then. When a
 * loss is confirmed (or such a settlement is voided), the copies in the
 * group's later, not yet settled projects are set again to the capital the
 * member really has left (IMS-V2/TRD.md §4.2: the smaller capital goes on).
 */
class GroupCapitalSync
{
    public function __construct(private CapitalCalculator $capital) {}

    /** @return int how many copies changed */
    public function afterSettlementOf(InvestmentProject $project): int
    {
        if (! $project->group_id) {
            return 0;
        }

        $laterProjectIds = InvestmentProject::query()
            ->where('group_id', $project->group_id)
            ->where('sequence_in_group', '>', (int) $project->sequence_in_group)
            ->where('status', '!=', 'settled')
            ->pluck('id');

        if ($laterProjectIds->isEmpty()) {
            return 0;
        }

        $changed = 0;

        foreach (InvestorGroupMember::query()->where('group_id', $project->group_id)->where('status', InvestorGroupMember::STATUS_ACTIVE)->get() as $member) {
            $left = $this->capital->forMember($member)['outstanding'];

            foreach ($member->investments()->whereIn('project_id', $laterProjectIds)->active()->get() as $row) {
                if (abs((float) $row->amount - $left) >= 0.01 && $left > 0) {
                    $row->forceFill(['amount' => $left])->save();
                    $changed++;
                }
            }
        }

        return $changed;
    }
}
