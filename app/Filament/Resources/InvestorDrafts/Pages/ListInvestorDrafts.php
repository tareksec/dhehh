<?php

namespace App\Filament\Resources\InvestorDrafts\Pages;

use App\Filament\Resources\InvestorDrafts\InvestorDraftResource;
use App\Filament\Resources\Investors\InvestorResource;
use App\Services\Investment\InvestorImportService;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListInvestorDrafts extends ListRecords
{
    protected static string $resource = InvestorDraftResource::class;

    public function getSubheading(): ?string
    {
        return __('Upload an Excel/CSV file or paste text to fill the investor form automatically. Review each draft, then confirm it to add it to the investor list.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importFile')
                ->label(__('Import Excel / CSV'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->visible(fn (): bool => InvestorDraftResource::canImport())
                ->schema([
                    FileUpload::make('file')
                        ->label(__('Excel or CSV file'))
                        ->disk('local')
                        ->directory('imports/investors')
                        ->visibility('private')
                        ->storeFileNamesIn('file_name')
                        ->acceptedFileTypes([
                            'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.oasis.opendocument.spreadsheet',
                        ])
                        ->maxSize(5120)
                        ->required()
                        ->helperText(__('Your usual INV sheet works as it is: a title line above the column names is fine, SL and total rows are skipped, and every sheet of an Excel file is read. Column names can be English or Bangla. Format NID and phone columns as Text in Excel so long numbers are not rounded.')),
                    ...$this->importInvestmentFields(),
                ])
                ->action(function (array $data, InvestorImportService $imports): void {
                    abort_unless(InvestorDraftResource::canImport(), 403);

                    $file = is_array($data['file'] ?? null) ? reset($data['file']) : ($data['file'] ?? null);

                    if (! $file) {
                        return;
                    }

                    $name = $data['file_name'] ?? null;
                    $name = is_array($name) ? (reset($name) ?: null) : $name;
                    $name ??= $file instanceof TemporaryUploadedFile ? $file->getClientOriginalName() : basename((string) $file);

                    try {
                        $result = $imports->importFile(Storage::disk('local')->path($file), (string) $name, $data);
                    } catch (ValidationException $exception) {
                        $this->notifyFailed($exception);

                        return;
                    } finally {
                        Storage::disk('local')->delete($file);
                    }

                    $this->notifyImported($result);
                }),

            Action::make('importText')
                ->label(__('Paste text'))
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->visible(fn (): bool => InvestorDraftResource::canImport())
                ->modalWidth('3xl')
                ->schema([
                    Textarea::make('text')
                        ->label(__('Investor details as text'))
                        ->rows(14)
                        ->maxLength(50000)
                        ->required()
                        ->placeholder(__("Name: Abdul Karim\nFather's Name: Abdul Rahim\nPhone: 01712345678\nNID: 1990123456789\nAddress: House 4, Dhaka\nNominee: Karima Begum\nRelation: Wife\n\nName: Next investor\nPhone: ..."))
                        ->helperText(__('One detail per line as "Label: value", with a blank line between investors. A table copied from Excel (with its header row) also works.')),
                    ...$this->importInvestmentFields(),
                ])
                ->action(function (array $data, InvestorImportService $imports): void {
                    abort_unless(InvestorDraftResource::canImport(), 403);

                    try {
                        $result = $imports->importText((string) ($data['text'] ?? ''), investment: $data);
                    } catch (ValidationException $exception) {
                        $this->notifyFailed($exception);

                        return;
                    }

                    $this->notifyImported($result);
                }),

            Action::make('sampleCsv')
                ->label(__('Sample CSV'))
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->visible(fn (): bool => InvestorDraftResource::canImport())
                ->action(fn (InvestorImportService $imports) => $imports->sampleCsv()),

            Action::make('investors')
                ->label(__('Investor list'))
                ->icon(Heroicon::OutlinedUserGroup)
                ->color('gray')
                ->url(InvestorResource::getUrl('index')),
        ];
    }

    /**
     * Optional project / payment method / date applied to every draft of
     * the import, so the sheet's investment amounts become investments on
     * confirm. Each draft can still be changed on review.
     *
     * @return list<Component|Field>
     */
    private function importInvestmentFields(): array
    {
        return [
            Section::make(__('Investment for this import (optional)'))
                ->description(__('Pick the project the sheet belongs to. Each row\'s investment amount is then added to that project when its draft is confirmed.'))
                ->schema(InvestorDraftResource::investmentFields(withAmount: false))
                ->columns(3)
                ->collapsible(),
        ];
    }

    /**
     * @param  array{created: int, ignored_columns: list<string>}  $result
     */
    private function notifyImported(array $result): void
    {
        Notification::make()
            ->title(__(':count investor drafts created. Review and confirm them below.', ['count' => $result['created']]))
            ->body($result['ignored_columns'] === [] ? null : __('These columns were not recognised and were left out: :columns', ['columns' => implode(', ', $result['ignored_columns'])]))
            ->success()
            ->send();
    }

    private function notifyFailed(ValidationException $exception): void
    {
        Notification::make()
            ->title(__('Nothing was imported'))
            ->body(implode("\n", $exception->validator->errors()->all()))
            ->danger()
            ->persistent()
            ->send();
    }
}
