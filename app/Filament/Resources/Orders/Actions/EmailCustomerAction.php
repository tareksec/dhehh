<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Models\EmailTemplate;
use App\Models\Order;
use App\Services\AuditLogService;
use App\Services\EmailTemplateService;
use App\Services\OrderStatusEmailService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * "Email the customer" on an order (owner request 2026-10-24): staff pick one
 * of the company's own email templates (Settings → Email Templates → My
 * templates) and it is mailed to the order's customer with this order's values
 * filled in, through the company's email account. The result is in the Email
 * Log. Shown only when the company has made at least one template and the
 * customer has an email address.
 */
class EmailCustomerAction
{
    public static function make(): Action
    {
        return Action::make('emailCustomer')
            ->label(__('Email the customer'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->visible(fn (Order $record): bool => static::allowed($record) && filled($record->customer?->email) && static::templates($record)->isNotEmpty())
            ->modalHeading(fn (Order $record): string => __('Email the customer: :order', ['order' => $record->order_number]))
            ->modalDescription(fn (Order $record): string => __('Sends the template you pick to :email, with this order\'s details filled in.', ['email' => (string) $record->customer?->email]))
            ->modalSubmitActionLabel(__('Send email'))
            ->schema(fn (Order $record): array => [
                Select::make('template')
                    ->label(__('Template'))
                    ->options(fn (): array => static::templates($record)->pluck('name', 'id')->all())
                    ->required()
                    ->live(),
                Placeholder::make('subject')
                    ->label(__('Subject'))
                    ->content(fn ($get): string => (string) (static::templates($record)->firstWhere('id', (int) $get('template'))?->subject ?? '-')),
            ])
            ->action(function (Order $record, array $data): void {
                abort_unless(static::allowed($record), 403);

                $template = static::templates($record)->firstWhere('id', (int) $data['template']);

                if ($template === null) {
                    return;
                }

                $problem = app(OrderStatusEmailService::class)->sendCustom($record, $template);

                if ($problem !== null) {
                    Notification::make()->title(__('The email was not sent'))->body($problem)->danger()->send();

                    return;
                }

                app(AuditLogService::class)->record('order.email_sent', $record, [], ['template' => $template->name]);

                Notification::make()->title(__('Email sent'))->body(__('Sent to :email.', ['email' => (string) $record->customer?->email]))->success()->send();
            });
    }

    /** @return Collection<int, EmailTemplate> */
    protected static function templates(Order $order)
    {
        return app(EmailTemplateService::class)->customTemplates((int) $order->company_id)->filter(fn (EmailTemplate $template): bool => filled($template->body))->values();
    }

    protected static function allowed(Order $order): bool
    {
        return Auth::user()?->hasPermission('sales.update') ?? false;
    }
}
