<?php

namespace App\Services;

use App\Ims\Settings\ImsSettings;
use App\Models\Order;
use App\Models\SmsTemplate;
use App\Models\StorefrontSetting;
use App\Support\BulkSmsBdPolicy;

/**
 * The wording of every SMS the owner can set (SMS Templates page, owner request
 * 2026-10-17).
 *
 * A template is plain text with :placeholders (the same style as the older
 * "Project completed message"). Two kinds:
 * - Code messages (sign-in / password-reset codes) have built-in wording, so
 *   a blank template still sends the code. BulkSMSBD's OTP format is
 *   "Your {Brand/Company Name} OTP is XXXX", so the owner can only choose the
 *   brand (BulkSmsBdPolicy::isCodeFormat); anything else is refused on save and
 *   ignored at send time.
 * - Order and project messages have no built-in wording: while a template is
 *   blank nothing is sent, so nobody is texted before the owner has written it.
 *   Each has a suggested wording the page can fill in.
 *
 * Every message follows BulkSmsBdPolicy (no single quotation mark, at most 3
 * SMS parts). Customer-facing wording is one text per message: the owner writes
 * it in the language the customers should get.
 */
class SmsTemplateService
{
    public const GROUP_ORDER = 'order';

    public const GROUP_CODE = 'code';

    public const GROUP_PROJECT = 'investor_project';

    /** IMS v2 news to investors (App\Ims\Notifications\ImsNotifier). */
    public const GROUP_IMS = 'ims';

    /** The one-time code that confirms an IMS invite (code format, built-in wording). */
    public const OTP_IMS_INVITE = 'otp.ims_invite';

    /** Suggested wording for the IMS news (plain English, one SMS, no link, no single quotation mark). */
    public const SUGGESTED_IMS = [
        'investment_confirmed' => 'Dear :name, we received your investment of Tk :amount. - :company',
        'profit_shared' => 'Dear :name, your profit of Tk :amount from :project is ready. - :company',
        'loss_shared' => 'Dear :name, :project closed with a loss. Your share: Tk :amount. Details are in the portal. - :company',
        'payout_paid' => 'Dear :name, we paid you Tk :amount. - :company',
        'capital_returned' => 'Dear :name, Tk :amount of your capital has been paid back. - :company',
        'maturity_near' => 'Dear :name, your investment matures on :date. - :company',
        'monthly_statement' => 'Dear :name, your statement for :date is ready in the portal. - :company',
        'project_report' => 'Dear :name, a new report on :project is in the portal. - :company',
        'project_update' => 'Dear :name, there is news about :project in the portal. - :company',
        'kyc_decided' => 'Dear :name, your KYC check is done. Please see the portal. - :company',
        'support_reply' => 'Dear :name, we answered your question in the portal. - :company',
        'feed_post' => 'Dear :name, there is a new post in the portal. - :company',
    ];

    /** The longest template (3 SMS parts of 160 characters). */
    public const MAX_LENGTH = 480;

    public const OTP_CUSTOMER_SIGNUP = 'otp.customer_signup';

    public const OTP_CUSTOMER_LOGIN = 'otp.customer_login';

    public const OTP_INVESTOR_LOGIN = 'otp.investor_login';

    public const RESET_CUSTOMER_PASSWORD = 'reset.customer_password';

    public const RESET_INVESTOR_PASSWORD = 'reset.investor_password';

    public const PROJECT_RUNNING = 'investor_project.running';

    public const PROJECT_CLOSED = 'investor_project.closed';

    public const PROJECT_SETTLED = 'investor_project.settled';

    /** Order stages a customer is texted about (Order::WORKFLOW_STAGES). */
    public const ORDER_STAGES = [
        Order::STAGE_CONFIRMED,
        Order::STAGE_PROCESSING,
        Order::STAGE_SHIPPING,
        Order::STAGE_DELIVERED,
        Order::STAGE_COMPLETED,
        Order::STAGE_CANCELLED,
        Order::STAGE_RETURNED,
        Order::STAGE_REFUNDED,
    ];

    /**
     * Wording the page can fill in for an order stage: short, plain English (one SMS), no link and no
     * single quotation mark, so it follows BulkSMSBD's rules. The owner reads it and saves it, or edits it first.
     */
    public const SUGGESTED_ORDER = [
        Order::STAGE_CONFIRMED => 'Dear :name, your order :order is confirmed. Total Tk :total. - :company',
        Order::STAGE_PROCESSING => 'Dear :name, your order :order is being prepared. - :company',
        Order::STAGE_SHIPPING => 'Dear :name, your order :order is on its way. Tracking: :tracking. - :company',
        Order::STAGE_DELIVERED => 'Dear :name, your order :order has been delivered. Thank you for shopping with :company.',
        Order::STAGE_COMPLETED => 'Dear :name, your order :order is complete. Thank you for shopping with :company.',
        Order::STAGE_CANCELLED => 'Dear :name, your order :order has been cancelled. - :company',
        Order::STAGE_RETURNED => 'Dear :name, your order :order has been marked as returned. - :company',
        Order::STAGE_REFUNDED => 'Dear :name, the refund for your order :order has been processed. - :company',
    ];

    /** Suggested wording for an investor project status (same rules as above). */
    public const SUGGESTED_PROJECT = [
        self::PROJECT_RUNNING => 'Dear :name, Project :project has started with your investment of Tk :amount. - :company',
        self::PROJECT_CLOSED => 'Dear :name, Project :project is complete. Your profit will be announced soon. - :company',
        self::PROJECT_SETTLED => 'Dear :name, the profit of Project :project is settled. Your profit: Tk :profit, total payout: Tk :payout. - :company',
    ];

    public function __construct(protected StorefrontNotificationService $notifications) {}

    public static function orderKey(string $stage): string
    {
        return 'order.'.$stage;
    }

    public static function imsKey(string $event): string
    {
        return 'ims.'.$event;
    }

    /**
     * Every message, in the order the page shows them.
     *
     * @return array<string, array{group: string, label: string, placeholders: array<string, string>, required: list<string>, default: ?string, suggested: ?string}>
     */
    public function definitions(): array
    {
        $order = [
            'name' => __('Customer name'),
            'order' => __('Order number'),
            'status' => __('Order status'),
            'total' => __('Order total'),
            'due' => __('Amount still due'),
            'tracking' => __('Courier tracking ID (empty until a parcel is booked)'),
            'company' => __('Company name'),
        ];
        $customerCode = [
            'company' => __('Company name'),
            'code' => __('The one-time code'),
        ];
        $investorCode = ['portal' => __('Portal name (company name + Investment Portal)')] + $customerCode;
        $project = [
            'name' => __('Investor name'),
            'project' => __('Project number in its group (or the project code)'),
            'group' => __('Group name (empty for a project outside a group)'),
            'amount' => __('Amount this investor put in this project'),
            'company' => __('Company name'),
        ];

        $definitions = [];

        foreach (self::ORDER_STAGES as $stage) {
            $definitions[self::orderKey($stage)] = [
                'group' => self::GROUP_ORDER,
                'label' => __(Order::WORKFLOW_STAGES[$stage]),
                'placeholders' => $order,
                'required' => [],
                'default' => null,
                'suggested' => self::SUGGESTED_ORDER[$stage],
            ];
        }

        $definitions[self::OTP_CUSTOMER_SIGNUP] = $this->codeDefinition(__('Sign-up code (shop customers and resellers)'), $customerCode, 'Your :company OTP is :code.');
        $definitions[self::OTP_CUSTOMER_LOGIN] = $this->codeDefinition(__('Login code (shop customers and resellers)'), $customerCode, 'Your :company OTP is :code.');
        $definitions[self::RESET_CUSTOMER_PASSWORD] = $this->codeDefinition(__('Password reset code (shop customers and resellers)'), $customerCode, 'Your :company OTP is :code.');
        $definitions[self::OTP_INVESTOR_LOGIN] = $this->codeDefinition(__('Investor login code'), $investorCode, 'Your :portal OTP is :code.');
        $definitions[self::RESET_INVESTOR_PASSWORD] = $this->codeDefinition(__('Investor password reset code'), $investorCode, 'Your :portal OTP is :code.');
        $definitions[self::OTP_IMS_INVITE] = $this->codeDefinition(__('Code to join IMS with an invite'), $investorCode, 'Your :portal OTP is :code.');

        $definitions[self::PROJECT_RUNNING] = [
            'group' => self::GROUP_PROJECT,
            'label' => __('Project started'),
            'placeholders' => $project,
            'required' => [],
            'default' => null,
            'suggested' => self::SUGGESTED_PROJECT[self::PROJECT_RUNNING],
        ];
        $definitions[self::PROJECT_CLOSED] = [
            'group' => self::GROUP_PROJECT,
            'label' => __('Project completed'),
            'placeholders' => $project,
            'required' => [],
            'default' => null,
            'suggested' => self::SUGGESTED_PROJECT[self::PROJECT_CLOSED],
        ];
        $definitions[self::PROJECT_SETTLED] = [
            'group' => self::GROUP_PROJECT,
            'label' => __('Profit settled'),
            'placeholders' => $project + [
                'profit' => __('This investor\'s profit share for the project'),
                'payout' => __('Total this investor is paid out (profit + any principal returned)'),
            ],
            'required' => [],
            'default' => null,
            'suggested' => self::SUGGESTED_PROJECT[self::PROJECT_SETTLED],
        ];

        $ims = [
            'name' => __('Investor name'),
            'amount' => __('The amount of this news'),
            'project' => __('Project name or code'),
            'date' => __('The date or month of this news'),
            'company' => __('Company name'),
        ];

        foreach (ImsSettings::EVENTS as $event => $label) {
            $definitions[self::imsKey($event)] = [
                'group' => self::GROUP_IMS,
                'label' => __($label),
                'placeholders' => $ims,
                'required' => [],
                'default' => null,
                'suggested' => self::SUGGESTED_IMS[$event] ?? null,
            ];
        }

        return $definitions;
    }

    /** @param array<string, string> $placeholders */
    protected function codeDefinition(string $label, array $placeholders, string $default): array
    {
        return [
            'group' => self::GROUP_CODE,
            'label' => $label,
            'placeholders' => $placeholders,
            'required' => ['code'],
            'default' => $default,
            'suggested' => null,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function definitionsInGroup(string $group): array
    {
        return array_filter($this->definitions(), fn (array $definition): bool => $definition['group'] === $group);
    }

    /** The company's own wording, or null when it has none. */
    public function body(int $companyId, string $key): ?string
    {
        $body = SmsTemplate::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->value('body');

        return filled($body) ? trim((string) $body) : null;
    }

    /** @return array<string, string> key => wording, only the ones that are written */
    public function bodies(int $companyId): array
    {
        return SmsTemplate::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->pluck('body', 'key')
            ->all();
    }

    /**
     * The text to send: the company's wording, else the built-in wording of a
     * code message, else null (nothing to send). The text is cleaned for
     * BulkSMSBD (BulkSmsBdPolicy::clean); a code message whose saved wording
     * is not the OTP format is replaced by the built-in text, so a code always
     * goes out in the format the gateway accepts.
     *
     * @param  array<string, scalar|null>  $values  placeholder name (without the colon) => value
     */
    public function render(int $companyId, string $key, array $values): ?string
    {
        $definition = $this->definitions()[$key] ?? null;

        if ($definition === null) {
            return null;
        }

        $custom = $this->body($companyId, $key);

        if ($custom !== null && $definition['group'] === self::GROUP_CODE && ! BulkSmsBdPolicy::isCodeFormat($custom)) {
            $custom = null;
        }

        $template = $custom ?? $definition['default'];

        return $template === null ? null : BulkSmsBdPolicy::clean($this->fill($template, $values));
    }

    /** @param array<string, scalar|null> $values */
    public function fill(string $template, array $values): string
    {
        $replace = [];

        foreach ($values as $name => $value) {
            $replace[':'.$name] = (string) $value;
        }

        return strtr($template, $replace);
    }

    /** Why a template cannot be saved (a missing placeholder or a BulkSMSBD rule), or null when it is fine. */
    public function problem(string $key, ?string $body): ?string
    {
        $definition = $this->definitions()[$key] ?? null;

        if ($definition === null || blank($body)) {
            return null;
        }

        $problems = [];

        foreach ($definition['required'] as $name) {
            if (! str_contains((string) $body, ':'.$name)) {
                $problems[] = __('This message must contain :placeholder, or the person receives no code.', ['placeholder' => ':'.$name]);
            }
        }

        $problems = [...$problems, ...BulkSmsBdPolicy::problems((string) $body, $definition['group'] === self::GROUP_CODE)];

        return $problems === [] ? null : implode(' ', $problems);
    }

    /**
     * Saves the wording of every message in one go; a blank text removes the
     * company's wording (code messages go back to the built-in text, the rest
     * send nothing).
     *
     * @param  array<string, ?string>  $bodies
     */
    public function save(int $companyId, array $bodies): void
    {
        $known = $this->definitions();

        foreach ($bodies as $key => $body) {
            if (! array_key_exists($key, $known)) {
                continue;
            }

            $body = filled($body) ? trim((string) $body) : null;
            $row = SmsTemplate::withoutGlobalScopes()->where('company_id', $companyId)->where('key', $key)->first();

            if ($body === null) {
                $row?->delete();

                continue;
            }

            if ($row === null) {
                SmsTemplate::withoutGlobalScopes()->create(['company_id' => $companyId, 'key' => $key, 'body' => $body]);
            } elseif ($row->body !== $body) {
                $row->update(['body' => $body]);
            }
        }
    }

    /**
     * The storefront settings row whose SMS gateway sends this company's
     * messages: the preferred one (an order's own site) when it has a gateway,
     * else the primary site, else the other.
     */
    public function gatewayFor(int $companyId, ?StorefrontSetting $preferred = null): ?StorefrontSetting
    {
        foreach ([$preferred, StorefrontSetting::primaryFor($companyId), StorefrontSetting::bladeFor($companyId), StorefrontSetting::nextjsFor($companyId)] as $setting) {
            if ($setting !== null && $this->notifications->smsConfigured($setting)) {
                return $setting;
            }
        }

        return null;
    }
}
