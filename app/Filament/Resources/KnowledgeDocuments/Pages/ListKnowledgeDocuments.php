<?php

namespace App\Filament\Resources\KnowledgeDocuments\Pages;

use App\Filament\Resources\KnowledgeDocuments\KnowledgeDocumentResource;
use App\Services\CompanyContext;
use App\Services\Crm\KnowledgeBaseService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class ListKnowledgeDocuments extends ListRecords
{
    protected static string $resource = KnowledgeDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testQuestion')
                ->label(__('Test a question'))
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('gray')
                ->modalHeading(__('Test a question'))
                ->modalDescription(__('Type a customer question to see which passages the AI would find. Nothing is sent and the AI itself is not called.'))
                ->modalSubmitActionLabel(__('Search'))
                ->schema([
                    TextInput::make('question')->label(__('Customer question'))->required()->maxLength(300),
                ])
                ->action(function (array $data): void {
                    $found = app(KnowledgeBaseService::class)->search((int) app(CompanyContext::class)->id(), (string) $data['question']);

                    if ($found === []) {
                        Notification::make()->warning()->title(__('No passage found for this question'))->body(__('The AI would not find an answer here. Add a document or use the words your customers use.'))->persistent()->send();

                        return;
                    }

                    $body = collect($found)->map(fn (array $passage): string => '<strong>'.e($passage['title']).'</strong><br>'.nl2br(e($passage['text'])))->implode('<br><br>');
                    Notification::make()->success()->title(__(':count passages found', ['count' => count($found)]))->body(new HtmlString($body))->persistent()->send();
                }),
            CreateAction::make(),
        ];
    }
}
