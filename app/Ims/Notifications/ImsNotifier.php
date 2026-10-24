<?php

namespace App\Ims\Notifications;

use App\Ims\Settings\ImsSettings;
use App\Jobs\SendImsMessageJob;
use App\Models\Company;
use App\Models\Investor;
use App\Models\InvestorPortalAccount;
use App\Notifications\ImsPortalNotification;
use App\Services\EmailTemplateService;
use App\Services\SmsTemplateService;
use App\Support\ImsNumbers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every piece of IMS news to an investor goes through here
 * (IMS-V2/TRD.md §8): always a line in the portal's notification list, and
 * an SMS and / or an e-mail when the owner has switched that event on in IMS
 * settings. The SMS wording is the owner's own (SMS Templates → IMS news);
 * without it no SMS goes out. Everything is written in the investor's
 * language and sent only after the money transaction has committed, so a
 * failed e-mail never undoes a payout.
 */
class ImsNotifier
{
    /** Title and text of each event, in plain words (IMS-V2/DESIGN.md §2). */
    public const MESSAGES = [
        'investment_confirmed' => ['We received your money', 'Your investment of :amount is confirmed and working.'],
        'profit_shared' => ['Your profit is ready', 'Your profit from :project is :amount.'],
        'loss_shared' => ['A project ended with a loss', ':project ended with a loss. :amount was taken from your capital.'],
        'payout_paid' => ['Money sent to you', 'We paid you :amount.'],
        'capital_returned' => ['Capital paid back', ':amount of your capital has been paid back.'],
        'maturity_near' => ['Your investment matures soon', 'Your investment of :amount matures on :date.'],
        'monthly_statement' => ['Your monthly statement', 'Your statement for :date is ready.'],
        'project_report' => ['New project report', 'A new report on :project is ready.'],
        'project_update' => ['News about your project', ':project: :title'],
        'kyc_decided' => ['Your KYC check is done', ':title'],
        'support_reply' => ['We answered your question', ':title'],
        'feed_post' => ['New post', ':title'],
    ];

    /**
     * @param  array{amount?: float|int|string|null, project?: string|null, date?: mixed, month?: string|null, title?: string|null, url?: string|null, pdf_name?: string|null, pdf_base64?: string|null}  $data
     */
    public function send(string $event, Investor $investor, array $data = []): void
    {
        DB::afterCommit(function () use ($event, $investor, $data): void {
            try {
                $this->deliver($event, $investor, $data);
            } catch (Throwable $exception) {
                // News never breaks the money work it reports on.
                report($exception);
            }
        });
    }

    /** @param  array<string, mixed>  $data */
    protected function deliver(string $event, Investor $investor, array $data): void
    {
        $company = Company::query()->find($investor->company_id);

        if (! $company || ! array_key_exists($event, self::MESSAGES)) {
            return;
        }

        $account = InvestorPortalAccount::query()->withoutGlobalScopes()->where('investor_id', $investor->getKey())->first();
        $locale = in_array($account?->locale, ['en', 'bn'], true) ? $account->locale : app()->getLocale();

        [$title, $body, $smsValues, $emailValues] = $this->inLocale($locale, function () use ($event, $investor, $data, $company): array {
            [$titleKey, $bodyKey] = self::MESSAGES[$event];
            $values = [
                'amount' => isset($data['amount']) ? ImsNumbers::money($data['amount'], $company->currency ?: 'BDT') : '',
                'project' => (string) ($data['project'] ?? ''),
                // A day (maturity) or a month ("2026-10", the monthly statement).
                'date' => isset($data['month'])
                    ? ImsNumbers::digits(Carbon::parse($data['month'].'-01')->locale(app()->getLocale())->translatedFormat('F Y'))
                    : (isset($data['date']) ? ImsNumbers::date($data['date']) : ''),
                'title' => (string) ($data['title'] ?? ''),
            ];

            return [
                __($titleKey),
                __($bodyKey, $values),
                [
                    'name' => $investor->name,
                    // The SMS keeps plain digits for the gateway.
                    'amount' => isset($data['amount']) ? number_format((float) $data['amount'], 0, '.', ',') : '',
                    'project' => $values['project'],
                    'date' => $values['date'],
                    'company' => $company->name,
                ],
                // What the owner's email wording (Email Templates → Investor emails) can use.
                [
                    'name' => $investor->name,
                    'amount' => $values['amount'],
                    'project' => $values['project'],
                    'date' => $values['date'],
                    'title' => $values['title'],
                    'company' => $company->name,
                ],
            ];
        });

        if ($account !== null) {
            $account->notify(new ImsPortalNotification($event, $title, $body, $data['url'] ?? null));
        }

        $sms = null;

        if (ImsSettings::channelOn($company, $event, 'sms') && filled($investor->phone)) {
            $sms = app(SmsTemplateService::class)->render((int) $company->getKey(), SmsTemplateService::imsKey($event), $smsValues);
        }

        $email = ImsSettings::channelOn($company, $event, 'email') && filled($investor->email) ? (string) $investor->email : null;

        // The owner's own wording for this news (branded email), else the built-in text above.
        $template = $email !== null ? app(EmailTemplateService::class)->render((int) $company->getKey(), EmailTemplateService::imsKey($event), $emailValues) : null;

        if ($sms !== null || $email !== null) {
            SendImsMessageJob::dispatch((int) $company->getKey(), (string) $investor->phone, $sms, $email, $title, [$body], $data['url'] ?? null, $data['pdf_name'] ?? null, $data['pdf_base64'] ?? null, $template);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function inLocale(string $locale, callable $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
