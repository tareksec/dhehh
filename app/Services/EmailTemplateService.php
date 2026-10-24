<?php

namespace App\Services;

use App\Ims\Notifications\ImsNotifier;
use App\Ims\Settings\ImsSettings;
use App\Mail\TemplatedEmail;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\StorefrontSetting;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * The wording of every email the owner can set (Settings → Email Templates,
 * owner request 2026-10-24); the email twin of SmsTemplateService.
 *
 * A template is a subject and a plain-text body with :placeholders. The mail
 * that goes out wraps the body in the company's branded layout (logo or name,
 * the site's colour, contact details), plus a plain-text version. Kinds:
 * - Order messages have no built-in wording: while a template has no body
 *   nothing is mailed, so nobody gets an email before the owner has written it.
 * - Code, password-reset, invitation and IMS-news messages have a built-in
 *   wording: with no template the mail goes out exactly as it did before
 *   (plain text); once the owner writes one, hers is used in the branded layout.
 * - Custom templates are the owner's own, sent by hand to an order's customer.
 *
 * Customer-facing wording is one text per message: the owner writes it in the
 * language the people should get (as with SMS templates).
 */
class EmailTemplateService
{
    public const GROUP_ORDER = 'order';

    public const GROUP_CODE = 'code';

    public const GROUP_IMS = 'ims';

    public const GROUP_CUSTOM = 'custom';

    public const ORDER_PLACED = 'order.placed';

    public const LOGIN_CODE = 'code.customer_login';

    public const RESELLER_RESET = 'reset.reseller_password';

    public const IMS_JOIN_CODE = 'code.ims_join';

    public const INVESTOR_RESET = 'reset.investor_password';

    public const IMS_INVITATION = 'ims.invitation';

    public const MAX_SUBJECT = 150;

    public const MAX_BODY = 5000;

    /** Minutes a customer's login code works (CustomerAccountService). */
    public const LOGIN_CODE_MINUTES = 10;

    /** @var array<string, array<string, mixed>>|null */
    protected ?array $definitions = null;

    /** Suggested subject and wording of the order emails: short and plain, the owner reads and saves or edits it. */
    public const SUGGESTED_ORDER_PLACED = [
        'We received your order :order',
        "Dear :name,\n\nThank you for your order. We received order :order.\n\nItems: :items\nTotal: Tk :total\nStill to pay: Tk :due\n\nWe will keep you updated by email.\n\n:company",
    ];

    public const SUGGESTED_ORDER = [
        Order::STAGE_CONFIRMED => ['Your order :order is confirmed', "Dear :name,\n\nYour order :order is confirmed. The total is Tk :total.\n\nThank you for shopping with :company."],
        Order::STAGE_PROCESSING => ['Your order :order is being prepared', "Dear :name,\n\nWe are preparing your order :order for shipping.\n\n:company"],
        Order::STAGE_SHIPPING => ['Your order :order is on its way', "Dear :name,\n\nYour order :order is on its way. Courier tracking ID: :tracking\n\n:company"],
        Order::STAGE_DELIVERED => ['Your order :order was delivered', "Dear :name,\n\nYour order :order has been delivered. Thank you for shopping with :company."],
        Order::STAGE_COMPLETED => ['Your order :order is complete', "Dear :name,\n\nYour order :order is complete. Thank you for shopping with :company."],
        Order::STAGE_CANCELLED => ['Your order :order was cancelled', "Dear :name,\n\nYour order :order has been cancelled. If you have a question, please contact us.\n\n:company"],
        Order::STAGE_RETURNED => ['Your order :order was returned', "Dear :name,\n\nYour order :order has been marked as returned.\n\n:company"],
        Order::STAGE_REFUNDED => ['The refund for your order :order', "Dear :name,\n\nThe refund for your order :order has been processed.\n\n:company"],
    ];

    public static function orderKey(string $stage): string
    {
        return 'order.'.$stage;
    }

    public static function imsKey(string $event): string
    {
        return 'ims.'.$event;
    }

    /**
     * Every built-in message, in the order the page shows them.
     *
     * @return array<string, array{group: string, label: string, placeholders: array<string, string>, required: list<string>, default_subject: ?string, default_body: ?string, suggested: ?array{0: string, 1: string}}>
     */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $order = $this->orderPlaceholders();
        $minutes = ['minutes' => __('Minutes the code or link works')];
        $code = ['company' => __('Company name'), 'code' => __('The one-time code')] + $minutes;
        $definitions = [];

        $definitions[self::ORDER_PLACED] = $this->definition(self::GROUP_ORDER, __('Order placed'), $order, [], null, null, self::SUGGESTED_ORDER_PLACED);

        foreach (SmsTemplateService::ORDER_STAGES as $stage) {
            $definitions[self::orderKey($stage)] = $this->definition(self::GROUP_ORDER, __(Order::WORKFLOW_STAGES[$stage]), $order, [], null, null, self::SUGGESTED_ORDER[$stage]);
        }

        $definitions[self::LOGIN_CODE] = $this->definition(
            self::GROUP_CODE,
            __('Login code (shop customers)'),
            $code,
            ['code'],
            __(':company login verification code'),
            __('Your :company login verification code is :code.')."\n\n".__('It expires in :minutes minutes and can be used only once. If you did not request this code, you can ignore this email.'),
        );
        $definitions[self::RESELLER_RESET] = $this->definition(
            self::GROUP_CODE,
            __('Reseller password reset link'),
            ['company' => __('Company name'), 'link' => __('The link to choose a new password')] + $minutes,
            ['link'],
            __(':company reseller password reset'),
            __('The :company team sent you this link so you can choose a new password for your reseller account.')."\n\n:link\n\n".__('The link works once and expires in :minutes minutes. If you did not expect this email, ignore it: your password stays the same.'),
        );
        $definitions[self::IMS_JOIN_CODE] = $this->definition(
            self::GROUP_CODE,
            __('Code to join IMS with an invite'),
            $code,
            ['code'],
            ':company — '.__('Your code to join'),
            __('Your code is :code. It works for :minutes minutes and only once.'),
        );
        $definitions[self::INVESTOR_RESET] = $this->definition(
            self::GROUP_CODE,
            __('Investor password reset code'),
            $code,
            ['code'],
            ':company — '.__('Reset your password'),
            __('Your password reset code is :code. It works for :minutes minutes and only once.')."\n\n".__('If you did not ask for this, you can ignore this e-mail. Your password stays the same.'),
        );

        $definitions[self::IMS_INVITATION] = $this->definition(
            self::GROUP_IMS,
            __('Invitation to join IMS'),
            ['company' => __('Company name'), 'link' => __('The link to join'), 'days' => __('Days the invitation is valid')],
            ['link'],
            ':company — '.__('Your invitation'),
            __('You are invited to invest with :company. Join here: :link (valid for :days days).'),
        );

        $ims = [
            'name' => __('Investor name'),
            'amount' => __('The amount of this news'),
            'project' => __('Project name or code'),
            'date' => __('The date or month of this news'),
            'title' => __('Title of this news'),
            'company' => __('Company name'),
        ];

        foreach (ImsSettings::EVENTS as $event => $label) {
            [$title, $body] = ImsNotifier::MESSAGES[$event];

            $definitions[self::imsKey($event)] = $this->definition(
                self::GROUP_IMS,
                __($label),
                $ims,
                [],
                __($title),
                __($body),
                [__($title), "Dear :name,\n\n".__($body)."\n\n:company"],
            );
        }

        return $this->definitions = $definitions;
    }

    /**
     * @param  array<string, string>  $placeholders
     * @param  list<string>  $required
     * @param  ?array{0: string, 1: string}  $suggested
     * @return array{group: string, label: string, placeholders: array<string, string>, required: list<string>, default_subject: ?string, default_body: ?string, suggested: ?array{0: string, 1: string}}
     */
    protected function definition(string $group, string $label, array $placeholders, array $required, ?string $subject, ?string $body, ?array $suggested = null): array
    {
        return [
            'group' => $group,
            'label' => $label,
            'placeholders' => $placeholders,
            'required' => $required,
            'default_subject' => $subject,
            'default_body' => $body,
            'suggested' => $suggested ?? ($subject !== null && $body !== null ? [$subject, $body] : null),
        ];
    }

    /** What an order email (and a custom template sent from an order) can use. @return array<string, string> */
    public function orderPlaceholders(): array
    {
        return [
            'name' => __('Customer name'),
            'order' => __('Order number'),
            'status' => __('Order status'),
            'items' => __('Items ordered (product names with quantities)'),
            'total' => __('Order total'),
            'due' => __('Amount still due'),
            'tracking' => __('Courier tracking ID (empty until a parcel is booked)'),
            'company' => __('Company name'),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function definitionsInGroup(string $group): array
    {
        return array_filter($this->definitions(), fn (array $definition): bool => $definition['group'] === $group);
    }

    /** The company's own template for a message when it has written the body, else null. */
    public function row(int $companyId, string $key): ?EmailTemplate
    {
        $row = EmailTemplate::withoutGlobalScopes()->where('company_id', $companyId)->where('key', $key)->first();

        return $row !== null && filled($row->body) ? $row : null;
    }

    public function written(int $companyId, string $key): bool
    {
        return $this->row($companyId, $key) !== null;
    }

    /** @return array<string, array{subject: ?string, body: string}> key => the written built-in templates */
    public function rows(int $companyId): array
    {
        return EmailTemplate::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_custom', false)
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->get()
            ->mapWithKeys(fn (EmailTemplate $row): array => [$row->key => ['subject' => $row->subject, 'body' => (string) $row->body]])
            ->all();
    }

    /** @return Collection<int, EmailTemplate> the owner's own templates, by name */
    public function customTemplates(int $companyId): Collection
    {
        return EmailTemplate::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_custom', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * The branded message to send for a written template, or null when the company has not written one.
     *
     * @param  array<string, scalar|null>  $values  placeholder name (without the colon) => value
     * @return array{subject: string, text: string, html: string}|null
     */
    public function render(int $companyId, string $key, array $values, ?EmailTemplate $row = null): ?array
    {
        $row ??= $this->row($companyId, $key);

        if ($row === null || blank($row->body)) {
            return null;
        }

        $definition = $this->definitions()[$key] ?? null;
        $subject = filled($row->subject) ? (string) $row->subject : (string) ($definition['default_subject'] ?? $row->name ?? '');

        return [
            'subject' => $this->cleanSubject($this->fill($subject, $values)),
            'text' => $this->fill((string) $row->body, $values),
            'html' => $this->toHtml((string) $row->body, $values),
        ];
    }

    /**
     * The mail to send: the company's template in the branded layout when it has written one, else
     * $fallback (the built-in mail, exactly as it went out before there were templates).
     *
     * @param  array<string, scalar|null>  $values
     */
    public function mailableOr(int $companyId, string $key, array $values, Mailable $fallback): Mailable
    {
        $message = $this->render($companyId, $key, $values);

        return $message === null ? $fallback : $this->wrap($companyId, $message);
    }

    /**
     * Puts a rendered message in the company's branded layout. A link becomes a button under the text.
     *
     * @param  array{subject: string, text: string, html: string}  $message
     */
    public function wrap(int $companyId, array $message, ?string $link = null, ?string $pdfName = null, ?string $pdfBase64 = null): TemplatedEmail
    {
        return new TemplatedEmail($message['subject'], $message['html'], $message['text'], $this->brand($companyId), $link, $pdfName, $pdfBase64);
    }

    /**
     * The company's look in an email: name, logo (an absolute address, or null), the site's colour and the
     * contact lines for the footer. Looking these up never stops an email, so a failure gives a plain name.
     *
     * @return array{name: string, logo: ?string, color: string, foreground: string, lines: list<string>}
     */
    public function brand(int $companyId): array
    {
        $company = Company::query()->find($companyId);
        $brand = ['name' => (string) ($company?->name ?: config('app.name')), 'logo' => null, 'color' => '#0F766E', 'foreground' => '#FFFFFF', 'lines' => []];

        try {
            $profile = $company ? app(CompanySettingsService::class)->profile($company) : [];
            $logo = trim((string) ($profile['logo_url'] ?? ''));
            $brand['logo'] = $logo === '' ? null : (Str::startsWith($logo, ['http://', 'https://']) ? $logo : url($logo));
            $brand['lines'] = array_values(array_filter(array_map(fn ($line): string => trim((string) $line), [$profile['address'] ?? '', $profile['phone'] ?? '', $profile['email'] ?? ''])));

            $site = StorefrontSetting::primaryFor($companyId);

            if ($site !== null) {
                $brand['color'] = $site->normalizedThemeColor();
                $brand['foreground'] = $site->themeForegroundColor();
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return $brand;
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

    /**
     * The body as HTML: the text and every value are escaped, a blank line starts a paragraph, a single
     * line break stays a line break, and an address starting with http(s):// becomes a link.
     *
     * @param  array<string, scalar|null>  $values
     */
    public function toHtml(string $template, array $values): string
    {
        $replace = [];

        foreach ($values as $name => $value) {
            $replace[':'.$name] = e((string) $value);
        }

        $html = strtr(e($template), $replace);
        $html = (string) preg_replace_callback('~https?://[^\s<]+~i', function (array $match): string {
            $url = $match[0];
            $tail = '';

            // A full stop or a closing bracket right after an address is part of the sentence, not of the address.
            while ($url !== '' && str_contains('.,:!?)', substr($url, -1))) {
                $tail = substr($url, -1).$tail;
                $url = substr($url, 0, -1);
            }

            return '<a href="'.$url.'" style="color:inherit;text-decoration:underline">'.$url.'</a>'.$tail;
        }, $html);

        return collect(preg_split('/\R{2,}/', trim($html)) ?: [])
            ->map(fn (string $paragraph): string => '<p style="margin:0 0 16px">'.nl2br(trim($paragraph), false).'</p>')
            ->implode("\n");
    }

    /** One line, no longer than a subject should be (a line break in a header would be an injection). */
    public function cleanSubject(string $subject): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $subject)), 190, '');
    }

    /**
     * Example values for the preview and the test email, so the owner sees a full message.
     *
     * @param  array<string, string>  $placeholders
     * @return array<string, string>
     */
    public function sampleValues(array $placeholders, string $company): array
    {
        $samples = [
            'name' => 'Rahim Uddin',
            'order' => 'ZZ-10245',
            'status' => __('Confirmed'),
            'items' => 'Wireless earbuds x2, Phone case x1',
            'total' => '3,450',
            'due' => '1,200',
            'tracking' => 'SF123456789',
            'company' => $company,
            'code' => '483920',
            'minutes' => '10',
            'link' => url('/'),
            'days' => '7',
            'amount' => '25,000',
            'project' => 'Project 3',
            'date' => now()->format('d M Y'),
            'title' => __('A new update'),
        ];

        $values = [];

        foreach (array_keys($placeholders) as $name) {
            $values[$name] = $samples[$name] ?? '...';
        }

        return $values;
    }

    /**
     * How a message looks (the subject and the branded HTML) with example values, from the text as it is
     * typed now, saved or not. A blank body shows the built-in wording of a built-in message, else null.
     *
     * @param  array<string, string>  $placeholders
     * @return array{subject: string, html: string}|null
     */
    public function preview(int $companyId, string $key, ?string $subject, ?string $body, array $placeholders): ?array
    {
        $definition = $this->definitions()[$key] ?? null;
        $body = filled($body) ? (string) $body : ($definition['default_body'] ?? null);

        if (blank($body)) {
            return null;
        }

        $brand = $this->brand($companyId);
        $row = new EmailTemplate(['company_id' => $companyId, 'key' => $key, 'subject' => $subject, 'body' => $body]);
        $message = $this->render($companyId, $key, $this->sampleValues($placeholders, $brand['name']), $row);

        return $message === null ? null : ['subject' => $message['subject'], 'html' => $this->wrap($companyId, $message)->render()];
    }

    /**
     * Why a template cannot be saved (a missing placeholder, or a missing subject where the subject has no
     * built-in), or null when it is fine. A template with no body is not a template: it is removed on save.
     */
    public function problem(string $key, ?string $subject, ?string $body): ?string
    {
        $definition = $this->definitions()[$key] ?? null;

        if ($definition === null || blank($body)) {
            return null;
        }

        $problems = [];

        if ($definition['default_subject'] === null && blank($subject)) {
            $problems[] = __('Write the subject too, or the email has none.');
        }

        foreach ($definition['required'] as $name) {
            if (! str_contains((string) $body, ':'.$name)) {
                $problems[] = __('This message must contain :placeholder, or the person receives nothing to use.', ['placeholder' => ':'.$name]);
            }
        }

        return $problems === [] ? null : implode(' ', $problems);
    }

    /**
     * Saves the wording of every built-in message in one go; a blank body removes the company's template
     * (code and IMS messages go back to the built-in mail, order messages send nothing).
     *
     * @param  array<string, array{subject?: ?string, body?: ?string}>  $templates
     */
    public function save(int $companyId, array $templates): void
    {
        $known = $this->definitions();

        foreach ($templates as $key => $template) {
            if (! array_key_exists($key, $known)) {
                continue;
            }

            $subject = filled($template['subject'] ?? null) ? trim((string) $template['subject']) : null;
            $body = filled($template['body'] ?? null) ? trim((string) $template['body']) : null;
            $row = EmailTemplate::withoutGlobalScopes()->where('company_id', $companyId)->where('key', $key)->first();

            if ($body === null) {
                $row?->delete();

                continue;
            }

            if ($row === null) {
                EmailTemplate::withoutGlobalScopes()->create(['company_id' => $companyId, 'key' => $key, 'subject' => $subject, 'body' => $body, 'is_custom' => false]);
            } elseif ($row->subject !== $subject || $row->body !== $body) {
                $row->update(['subject' => $subject, 'body' => $body]);
            }
        }
    }

    /**
     * Saves the owner's own templates: a row with an id is updated, one without is created, and a template
     * missing from the list is deleted. A row with no name and no body is dropped.
     *
     * @param  list<array{id?: int|string|null, name?: ?string, subject?: ?string, body?: ?string}>  $items
     */
    public function saveCustom(int $companyId, array $items): void
    {
        $keep = [];

        foreach ($items as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            $subject = filled($item['subject'] ?? null) ? trim((string) $item['subject']) : null;
            $body = filled($item['body'] ?? null) ? trim((string) $item['body']) : null;

            if ($name === '' && $body === null) {
                continue;
            }

            $row = filled($item['id'] ?? null)
                ? EmailTemplate::withoutGlobalScopes()->where('company_id', $companyId)->where('is_custom', true)->whereKey((int) $item['id'])->first()
                : null;

            $attributes = ['name' => $name !== '' ? $name : __('Untitled'), 'subject' => $subject, 'body' => $body];

            if ($row === null) {
                $row = EmailTemplate::withoutGlobalScopes()->create($attributes + ['company_id' => $companyId, 'key' => 'custom.'.Str::lower((string) Str::ulid()), 'is_custom' => true]);
            } else {
                $row->update($attributes);
            }

            $keep[] = $row->getKey();
        }

        EmailTemplate::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_custom', true)
            ->whereNotIn('id', $keep)
            ->delete();
    }
}
