<?php

namespace App\Console\Commands\Ims;

use App\Ims\Todo\OwnerTodo;
use App\Mail\ImsMessage;
use App\Models\Company;
use App\Models\EmailLog;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\StorefrontNotificationService;
use App\Support\ImsNumbers;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

/**
 * Every morning: the office's "what to do today" (PRD FR-66) to the owner and
 * managers — a bell notification in the office and an e-mail. Nothing when the
 * list is empty; never twice on the same day.
 */
class SendOwnerDigest extends ImsCompanyCommand
{
    protected $signature = 'ims:owner-digest {--company= : Company id (default: every company with IMS on)}';

    protected $description = 'IMS v2: send the office its to-do list for today.';

    protected function job(): string
    {
        return 'owner_digest';
    }

    protected function runFor(Company $company): int
    {
        $items = app(OwnerTodo::class)->items();

        if ($items === [] || ! Cache::add('ims.owner-digest.'.$company->getKey().'.'.now()->toDateString(), true, now()->addDay())) {
            return 0;
        }

        $lines = array_map(fn (array $item): string => ImsNumbers::count($item['count']).' — '.$item['label'], $items);
        $officeUrl = (string) $company->imsOfficeUrl();
        $setting = StorefrontSetting::primaryFor((int) $company->getKey());
        $sent = 0;

        foreach ($this->recipients($company) as $user) {
            Notification::make()->title(__('What to do today'))->body(implode("\n", $lines))->sendToDatabase($user);

            if ($setting !== null && filled($user->email)) {
                app(StorefrontNotificationService::class)->sendMailable($setting, (string) $user->email, new ImsMessage($company->name, __('What to do today'), $lines, $officeUrl), EmailLog::KIND_OWNER_DIGEST);
            }

            $sent++;
        }

        return $sent;
    }

    /** @return iterable<User> */
    private function recipients(Company $company): iterable
    {
        $users = $company->users()->where('users.is_active', true)->get();
        $owners = User::query()->where('role', 'super_admin')->where('is_active', true)->get();

        return $users->merge($owners)->unique('id')
            ->filter(fn (User $user): bool => $user->hasPermission('ims.office.access') && ($user->isSuperAdmin() || $user->hasPermission('ims.proposals.approve')));
    }
}
