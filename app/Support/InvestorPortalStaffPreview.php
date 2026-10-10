<?php

namespace App\Support;

use App\Models\InvestorPortalAccount;
use Illuminate\Http\Request;

/**
 * "Staff preview" of the investor portal: a staff member with
 * investments.portal_manage opens the portal signed in as one investor or
 * channel partner, in a new tab, from that person's page in the dashboard.
 *
 * The state lives only in the portal browser's own session (set once by the
 * one-time staff-entry link), never on the account, so the person's own
 * sign-ins are untouched. A preview is look-only: it may enter an account
 * that has no password yet, it is not counted as the person signing in, it
 * cannot change a password, and anything it records in the audit log is
 * attributed to the staff member.
 */
final class InvestorPortalStaffPreview
{
    public const SESSION_KEY = 'investor_portal_staff_preview';

    /** @return array{account_id: int, staff_user_id: int, staff_name: string}|null */
    public static function current(): ?array
    {
        // The app's session store is the one StartSession gives the request, so this
        // reads the same data on a page load, a Livewire update and in a test.
        if (! app()->bound('session.store')) {
            return null;
        }

        $value = app('session.store')->get(self::SESSION_KEY);

        return is_array($value) && isset($value['account_id'], $value['staff_user_id'])
            ? ['account_id' => (int) $value['account_id'], 'staff_user_id' => (int) $value['staff_user_id'], 'staff_name' => (string) ($value['staff_name'] ?? '')]
            : null;
    }

    /** True while this browser session is a staff preview of exactly this account. */
    public static function isFor(?InvestorPortalAccount $account): bool
    {
        $preview = self::current();

        return $account !== null
            && $account->getKey() !== null
            && $preview !== null
            && $preview['account_id'] === (int) $account->getKey();
    }

    public static function start(Request $request, InvestorPortalAccount $account, int $staffUserId, string $staffName): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'account_id' => (int) $account->getKey(),
            'staff_user_id' => $staffUserId,
            'staff_name' => $staffName,
        ]);
    }
}
