<?php

namespace App\Filament\Ims\Portal\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Ims\Kyc\KycService;
use App\Models\Investor;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * KYC in the portal (IMS-V2/PRD.md FR-10–14, DESIGN §7 KycCapture): the
 * front and back of the NID card and a selfie, taken with the phone camera
 * and made smaller on the phone before they are sent. Shows where the KYC
 * stands and, when it was turned down, why.
 */
class Kyc extends Page
{
    use InvestorPortalPage;

    protected static ?string $portalRole = 'investor';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.ims.portal.kyc';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('Account');
    }

    public static function getNavigationLabel(): string
    {
        return __('KYC');
    }

    public function getTitle(): string
    {
        return __('KYC — prove who you are');
    }

    public function investor(): Investor
    {
        return $this->account()->investor;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        $camera = fn (FileUpload $upload, string $capture): FileUpload => $upload
            ->image()
            ->storeFiles(false)
            ->required()
            ->maxSize(8192)
            // Smaller on the phone before it is sent (DESIGN §7).
            ->imageResizeMode('contain')
            ->imageResizeTargetWidth('1600')
            ->imageResizeTargetHeight('1600')
            ->extraInputAttributes(['capture' => $capture, 'accept' => 'image/*']);

        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('nid_number')->label(__('NID number'))->required()->maxLength(30)
                    ->visible(fn (): bool => blank($this->investor()->nid_number)),
                DatePicker::make('date_of_birth')->label(__('Date of birth'))->required()->maxDate(now()->subYears(18))
                    ->visible(fn (): bool => blank($this->investor()->date_of_birth)),
                $camera(FileUpload::make('nid_front')->label(__('Front of the NID card')), 'environment'),
                $camera(FileUpload::make('nid_back')->label(__('Back of the NID card')), 'environment'),
                $camera(FileUpload::make('selfie')->label(__('A selfie (your face, clearly)')), 'user'),
            ]);
    }

    public function canSend(): bool
    {
        return ! $this->isStaffPreview() && in_array($this->investor()->kyc_status, [Investor::KYC_NOT_SUBMITTED, Investor::KYC_REJECTED, Investor::KYC_PENDING], true);
    }

    public function send(): void
    {
        $this->refuseInStaffPreview();
        abort_unless($this->canSend(), 403);

        $data = $this->form->getState();

        app(KycService::class)->submit(
            $this->investor(),
            array_filter(['nid_front' => $data['nid_front'] ?? null, 'nid_back' => $data['nid_back'] ?? null, 'selfie' => $data['selfie'] ?? null]),
            'investor',
            $this->account()->getKey(),
            ['nid_number' => $data['nid_number'] ?? null, 'date_of_birth' => $data['date_of_birth'] ?? null],
        );

        Notification::make()->title(__('Thank you. The office will check your KYC soon.'))->success()->send();
        $this->form->fill();
    }
}
