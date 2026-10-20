<?php

namespace App\Filament\Ims\Office\Resources\ProjectReports\Pages;

use App\Filament\Ims\Office\Resources\ProjectReports\ProjectReportResource;
use App\Models\InvestmentProject;
use App\Models\ProjectReport;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListProjectReports extends ListRecords
{
    protected static string $resource = ProjectReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')
                ->label(__('New report'))
                ->icon('heroicon-o-document-plus')
                ->schema([
                    Select::make('project_id')->label(__('Project'))
                        ->options(fn (): array => InvestmentProject::query()->orderByDesc('id')->pluck('name', 'id')->all())
                        ->searchable()->required(),
                    TextInput::make('title')->label(__('Title'))->required()->maxLength(255),
                    DatePicker::make('period_start')->label(__('From')),
                    DatePicker::make('period_end')->label(__('To')),
                    Textarea::make('notes')->label(__('What to tell investors'))->rows(5),
                ])
                ->action(function (array $data): void {
                    $project = InvestmentProject::query()->findOrFail($data['project_id']);

                    ProjectReport::query()->create([
                        'company_id' => $project->company_id,
                        'project_id' => $project->getKey(),
                        'title' => $data['title'],
                        'period_start' => $data['period_start'] ?? null,
                        'period_end' => $data['period_end'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'created_by' => auth()->id(),
                    ]);

                    Notification::make()->title(__('Draft saved. Publish it when it is ready.'))->success()->send();
                }),
        ];
    }
}
