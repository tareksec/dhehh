<?php

namespace App\Filament\Resources\Investors\Pages;

use App\Filament\Resources\InvestorDrafts\InvestorDraftResource;
use App\Filament\Resources\Investors\InvestorResource;
use App\Models\InvestorDraft;
use App\Services\CompanyContext;
use App\Services\Investment\InvestorDocumentUploadService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

class ListInvestors extends ListRecords
{
    protected static string $resource = InvestorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importInvestors')
                ->label(fn (): string => ($drafts = InvestorDraft::query()->count()) > 0
                    ? __('Import / drafts (:count)', ['count' => $drafts])
                    : __('Import from Excel / CSV / text'))
                ->icon(Heroicon::OutlinedDocumentArrowUp)
                ->color('gray')
                ->visible(fn (): bool => InvestorDraftResource::canViewAny())
                ->url(fn (): string => InvestorDraftResource::getUrl('index')),
            Action::make('uploadDocumentsByNid')
                ->label(__('Upload documents by NID'))
                ->icon(Heroicon::OutlinedIdentification)
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->hasPermission('investments.manage') ?? false)
                ->modalDescription(__('Name each file with the investor\'s NID / passport number and what it is, e.g. 1479939603_nid.jpg, 1479939603_nominee.jpg, 1479939603_contract.pdf, 1479939603_cheque.jpg, 1479939603_stamp.jpg. Each file is attached to the investor with that NID.'))
                ->schema([
                    FileUpload::make('files')
                        ->label(__('Scanned copies'))
                        ->multiple()
                        ->maxFiles(200)
                        ->disk('local')
                        ->directory('imports/investor-documents')
                        ->visibility('private')
                        ->storeFileNamesIn('file_names')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(10240)
                        ->previewable(false)
                        ->required(),
                ])
                ->action(function (array $data, InvestorDocumentUploadService $uploads): void {
                    abort_unless(auth()->user()?->hasPermission('investments.manage'), 403);

                    $company = app(CompanyContext::class)->company();
                    $stored = array_values((array) ($data['files'] ?? []));
                    $names = (array) ($data['file_names'] ?? []);

                    if (! $company) {
                        Storage::disk('local')->delete($stored);
                        Notification::make()->title(__('Switch to a single company before uploading documents.'))->danger()->send();

                        return;
                    }

                    $files = array_map(fn (string $path): array => [
                        'path' => Storage::disk('local')->path($path),
                        'name' => (string) ($names[$path] ?? basename($path)),
                    ], $stored);

                    try {
                        $result = $uploads->attach($files, $company);
                    } finally {
                        Storage::disk('local')->delete($stored);
                    }

                    Notification::make()
                        ->title(__(':count documents attached', ['count' => $result['attached']]))
                        ->body($result['unmatched'] === [] ? null : __('No investor with this NID / passport number for: :files', ['files' => implode(', ', $result['unmatched'])]))
                        ->status($result['unmatched'] === [] ? 'success' : 'warning')
                        ->persistent($result['unmatched'] !== [])
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
