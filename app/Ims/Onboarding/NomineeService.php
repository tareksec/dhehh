<?php

namespace App\Ims\Onboarding;

use App\Models\Investor;
use App\Models\InvestorNominee;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An investor's nominees (IMS-V2/PRD.md FR-06): one to five; with more than
 * one, their shares add up to exactly 100%. The first nominee is also kept
 * on the investor's own nominee fields, which the ERP's forms and
 * documents still read.
 */
class NomineeService
{
    /**
     * Replaces the investor's nominees with this list.
     *
     * @param  list<array{name?: string|null, relation?: string|null, phone?: string|null, nid?: string|null, address?: string|null, share_percent?: float|int|string|null}>  $rows
     */
    public function replace(Investor $investor, array $rows, string $byType = 'staff'): void
    {
        $rows = array_values(array_filter($rows, fn (array $row): bool => filled($row['name'] ?? null)));
        $this->validate($rows);

        DB::transaction(function () use ($investor, $rows, $byType): void {
            $before = $investor->nominees()->get(['name', 'relation', 'share_percent'])->toArray();
            $investor->nominees()->get()->each->delete();

            foreach ($rows as $row) {
                InvestorNominee::query()->create([
                    'company_id' => $investor->company_id,
                    'investor_id' => $investor->getKey(),
                    'name' => trim((string) $row['name']),
                    'relation' => $row['relation'] ?? null,
                    'phone' => $row['phone'] ?? null,
                    'nid' => filled($row['nid'] ?? null) ? (string) $row['nid'] : null,
                    'address' => $row['address'] ?? null,
                    'share_percent' => count($rows) === 1 ? 100 : round((float) ($row['share_percent'] ?? 0), 2),
                ]);
            }

            $first = $rows[0] ?? null;
            $investor->forceFill([
                'nominee_name' => $first['name'] ?? null,
                'nominee_relation' => $first['relation'] ?? null,
                'nominee_phone' => $first['phone'] ?? null,
                'nominee_nid_or_passport' => $first['nid'] ?? null,
                'nominee_address' => $first['address'] ?? null,
            ])->save();

            app(AuditLogService::class)->record('ims.nominees_saved', $investor, ['nominees' => $before], [
                'nominees' => array_map(fn (array $row): array => ['name' => $row['name'], 'share_percent' => $row['share_percent'] ?? 100], $rows),
                'by' => $byType,
            ]);
        });
    }

    /** @param  list<array<string, mixed>>  $rows */
    public function validate(array $rows): void
    {
        if (count($rows) > InvestorNominee::MAX) {
            throw ValidationException::withMessages(['nominees' => __('Add at most :count nominees.', ['count' => InvestorNominee::MAX])]);
        }

        if (count($rows) > 1) {
            $total = round(array_sum(array_map(fn (array $row): float => (float) ($row['share_percent'] ?? 0), $rows)), 2);

            if (abs($total - 100) > 0.001) {
                throw ValidationException::withMessages(['nominees' => __('The nominees\' shares must add up to exactly 100%. Now they add up to :total%.', ['total' => rtrim(rtrim(number_format($total, 2), '0'), '.')])]);
            }
        }
    }
}
