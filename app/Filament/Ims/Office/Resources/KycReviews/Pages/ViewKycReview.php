<?php

namespace App\Filament\Ims\Office\Resources\KycReviews\Pages;

use App\Filament\Ims\Office\Resources\KycReviews\KycReviewResource;
use App\Ims\Kyc\KycService;
use App\Models\Investor;
use App\Models\InvestorKycDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;

/**
 * One investor's KYC: the latest picture of each kind side by side, the
 * checklist with green ticks and red crosses, and the decision.
 */
class ViewKycReview extends ViewRecord
{
    protected static string $resource = KycReviewResource::class;

    protected string $view = 'filament.ims.office.kyc-review';

    public function getTitle(): string
    {
        return __('KYC — :name', ['name' => $this->record->name]);
    }

    /** @return Collection<string, InvestorKycDocument> type => latest picture */
    public function pictures(): Collection
    {
        return $this->record->kycDocuments()->latest('id')->get()->unique('type')->keyBy('type');
    }

    /** @return array<string, array<string, mixed>> */
    public function checklist(): array
    {
        $saved = $this->record->kyc_check ?? [];

        return app(KycService::class)->checklist($this->record, $saved['ocr']['state'] ?? null);
    }

    protected function getHeaderActions(): array
    {
        $canReview = fn (): bool => auth()->user()?->hasPermission('ims.kyc.review') ?? false;

        return [
            Action::make('approve')
                ->label(__('Approve KYC'))
                ->color('success')
                ->icon('heroicon-o-check-badge')
                ->visible(fn (): bool => $canReview() && $this->record->kyc_status === Investor::KYC_PENDING)
                ->requiresConfirmation()
                ->modalDescription(__('The investor becomes verified and can put in new money. Yes, approve?'))
                ->action(function (): void {
                    app(KycService::class)->approve($this->record, auth()->user());
                    Notification::make()->title(__('KYC approved'))->success()->send();
                    $this->refreshFormData([]);
                }),
            Action::make('reject')
                ->label(__('Turn down'))
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->visible(fn (): bool => $canReview() && $this->record->kyc_status === Investor::KYC_PENDING)
                ->schema([
                    Textarea::make('reason')->label(__('Reason (the investor sees it)'))->required()->minLength(5)->rows(3),
                ])
                ->action(function (array $data): void {
                    app(KycService::class)->reject($this->record, auth()->user(), (string) $data['reason']);
                    Notification::make()->title(__('KYC turned down'))->success()->send();
                }),
            Action::make('upload')
                ->label(__('Send KYC for the investor'))
                ->color('gray')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => (auth()->user()?->hasPermission('investments.manage') ?? false) && ! $this->record->isKycVerified())
                ->schema([
                    TextInput::make('nid_number')->label(__('NID number'))->visible(fn (): bool => blank($this->record->nid_number)),
                    DatePicker::make('date_of_birth')->label(__('Date of birth'))->visible(fn (): bool => blank($this->record->date_of_birth)),
                    FileUpload::make('nid_front')->label(__('NID front'))->image()->storeFiles(false)->required(),
                    FileUpload::make('nid_back')->label(__('NID back'))->image()->storeFiles(false)->required(),
                    FileUpload::make('selfie')->label(__('Selfie'))->image()->storeFiles(false)->required(),
                ])
                ->action(function (array $data): void {
                    app(KycService::class)->submit(
                        $this->record,
                        array_filter(['nid_front' => $data['nid_front'] ?? null, 'nid_back' => $data['nid_back'] ?? null, 'selfie' => $data['selfie'] ?? null]),
                        'staff',
                        auth()->id(),
                        ['nid_number' => $data['nid_number'] ?? null, 'date_of_birth' => $data['date_of_birth'] ?? null],
                    );
                    Notification::make()->title(__('KYC sent for review'))->success()->send();
                }),
        ];
    }
}
