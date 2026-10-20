<?php

namespace App\Filament\Actions;

use App\Models\ChannelPartnerPayout;
use App\Models\Company;
use App\Models\SettlementPayout;
use App\Services\CompanyStorageService;
use App\Services\Investment\PayoutProofService;
use App\Support\ImsPanelLinks;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The payment screenshot on a payout (16_INVESTOR_GROUPS_PLAN.md §3.6): the
 * upload field used when marking a payout paid, the "Payment screenshot"
 * action for a payout that is already paid, and the table column that shows
 * whether one is there. Shared by the investor payouts, the channel-partner
 * payouts and the payout-batch items.
 */
final class PayoutProofAction
{
    /** @param  Closure(): Company  $company  the company that owns the payout */
    public static function field(Closure $company): FileUpload
    {
        return FileUpload::make('payment_proof')
            ->label(__('Payment screenshot'))
            ->helperText(__('A screenshot or slip of the payment (image or PDF). The person paid can see it in their portal.'))
            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(5120)
            ->disk(fn (): string => app(CompanyStorageService::class)->privateDiskName())
            ->directory(fn (): string => app(CompanyStorageService::class)->privateDirectory($company(), 'payout-proofs'))
            ->visibility('private')
            ->previewable(false)
            ->openable(false);
    }

    /**
     * Upload (or replace) the screenshot of a payout that is already paid.
     *
     * @param  Closure(): Company  $company
     * @param  Closure(mixed): (SettlementPayout|ChannelPartnerPayout|null)|null  $payout  maps the table record to its payout (default: the record itself)
     */
    public static function uploadAction(Closure $company, ?Closure $payout = null): Action
    {
        $resolve = $payout ?? fn ($record) => $record;

        return Action::make('paymentProof')
            ->label(fn ($record): string => $resolve($record)?->hasPaymentProof() ? __('Replace screenshot') : __('Payment screenshot'))
            ->icon('heroicon-o-camera')
            ->color('gray')
            ->visible(fn ($record): bool => ($resolve($record)?->payment_status === 'paid') && (auth()->user()?->hasPermission('investments.settle') ?? false))
            ->schema([self::field($company)->required()])
            ->action(function ($record, array $data) use ($resolve): void {
                if (! self::attach($resolve($record), $data['payment_proof'] ?? null)) {
                    return;
                }

                Notification::make()->success()->title(__('Payment screenshot saved. The person paid can see it in their portal.'))->send();
            });
    }

    /** Whether the payout has a screenshot, as a tick that opens it. */
    public static function column(?Closure $payout = null): IconColumn
    {
        $resolve = $payout ?? fn ($record) => $record;

        return IconColumn::make('payment_proof_present')
            ->label(__('Screenshot'))
            ->boolean()
            ->state(fn ($record): bool => (bool) $resolve($record)?->hasPaymentProof())
            ->url(fn ($record): ?string => ($document = $resolve($record)?->paymentProof()) ? ImsPanelLinks::route('investment-documents.download', $document) : null)
            ->openUrlInNewTab();
    }

    /** Saves the uploaded file against the payout; tells staff why when it cannot. */
    public static function attach(SettlementPayout|ChannelPartnerPayout|null $payout, ?string $storedPath): bool
    {
        if ($payout === null || blank($storedPath)) {
            return false;
        }

        try {
            app(PayoutProofService::class)->attach($payout, $storedPath, auth()->user());
        } catch (ValidationException $exception) {
            self::discard($storedPath);
            Notification::make()->danger()->title(__('Could not save the screenshot'))->body(collect($exception->errors())->flatten()->first())->send();

            return false;
        }

        return true;
    }

    /** A file nobody will use (the payout was not saved): do not leave it behind. */
    public static function discard(?string $storedPath): void
    {
        if (blank($storedPath)) {
            return;
        }

        try {
            Storage::disk(app(CompanyStorageService::class)->privateDiskName())->delete($storedPath);
        } catch (Throwable) {
            // Best effort: a stray private file is harmless.
        }
    }
}
