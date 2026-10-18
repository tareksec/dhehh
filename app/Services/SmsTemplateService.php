<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SmsTemplate;
use App\Models\StorefrontSetting;

/**
 * The wording of every SMS the owner can set (SMS Templates page, owner request
 * 2026-10-17).
 *
 * A template is plain text with :placeholders (the same style as the older
 * "Project completed message"). Two kinds:
 * - Code messages (sign-in / password-reset codes) have built-in wording, so
 *   a blank template still sends the code; a template must contain :code.
 * - Order and project messages have no built-in wording: while a template is
 *   blank nothing is sent, so nobody is texted before the owner has written it.
 *
 * Customer-facing wording is one text per message: the owner writes it in the
 * language the customers should get.
 */
class SmsTemplateService
{
    public const GROUP_ORDER = 'order';

    public const GROUP_CODE = 'code';

    public const GROUP_PROJECT = 'investor_project';

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

    public function __construct(protected StorefrontNotificationService $notifications) {}

    public static function orderKey(string $stage): string
    {
        return 'order.'.$stage;
    }

    /**
     * Every message, in the order the page shows them.
     *
     * @return array<string, array{group: string, label: string, placeholders: array<string, string>, required: list<string>, default: ?string, translate: bool}>
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
            'minutes' => __('Minutes the code stays valid'),
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
                'translate' => false,
            ];
        }

        $definitions[self::OTP_CUSTOMER_SIGNUP] = $this->codeDefinition(__('Sign-up code (shop customers and resellers)'), $customerCode, 'Your :company OTP is :code. Use it to verify your account. It expires in :minutes minutes.', false);
        $definitions[self::OTP_CUSTOMER_LOGIN] = $this->codeDefinition(__('Login code (shop customers and resellers)'), $customerCode, 'Your :company OTP is :code. Use it to log in. It expires in :minutes minutes.', false);
        $definitions[self::RESET_CUSTOMER_PASSWORD] = $this->codeDefinition(__('Password reset code (shop customers and resellers)'), $customerCode, 'Your :company OTP is :code. Use it to reset your password. It expires in :minutes minutes.', false);
        $definitions[self::OTP_INVESTOR_LOGIN] = $this->codeDefinition(__('Investor login code'), $investorCode, ':portal login OTP is :code. It expires in 1 minute.', true);
        $definitions[self::RESET_INVESTOR_PASSWORD] = $this->codeDefinition(__('Investor password reset code'), $investorCode, ':portal password reset OTP is :code. It expires in 1 minute.', true);

        $definitions[self::PROJECT_RUNNING] = [
            'group' => self::GROUP_PROJECT,
            'label' => __('Project started'),
            'placeholders' => $project,
            'required' => [],
            'default' => null,
            'translate' => false,
        ];
        $definitions[self::PROJECT_CLOSED] = [
            'group' => self::GROUP_PROJECT,
            'label' => __('Project completed'),
            'placeholders' => $project,
            'required' => [],
            'default' => null,
            'translate' => false,
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
            'translate' => false,
        ];

        return $definitions;
    }

    /** @param array<string, string> $placeholders */
    protected function codeDefinition(string $label, array $placeholders, string $default, bool $translate): array
    {
        return [
            'group' => self::GROUP_CODE,
            'label' => $label,
            'placeholders' => $placeholders,
            'required' => ['code'],
            'default' => $default,
            'translate' => $translate,
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
     * code message, else null (nothing to send).
     *
     * @param  array<string, scalar|null>  $values  placeholder name (without the colon) => value
     */
    public function render(int $companyId, string $key, array $values, ?string $locale = null): ?string
    {
        $definition = $this->definitions()[$key] ?? null;

        if ($definition === null) {
            return null;
        }

        $custom = $this->body($companyId, $key);

        if ($custom !== null) {
            return $this->fill($custom, $values);
        }

        if ($definition['default'] === null) {
            return null;
        }

        return $definition['translate']
            ? __($definition['default'], array_map('strval', $values), $locale)
            : $this->fill($definition['default'], $values);
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

    /** Why a template cannot be saved, or null when it is fine. */
    public function problem(string $key, ?string $body): ?string
    {
        $definition = $this->definitions()[$key] ?? null;

        if ($definition === null || blank($body)) {
            return null;
        }

        foreach ($definition['required'] as $name) {
            if (! str_contains((string) $body, ':'.$name)) {
                return __('This message must contain :placeholder, or the person receives no code.', ['placeholder' => ':'.$name]);
            }
        }

        return null;
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
