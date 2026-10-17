<?php

namespace App\Filament\Resources\InvestorGroups\Pages;

use App\Filament\Resources\InvestmentProjects\InvestmentProjectResource;
use App\Filament\Resources\InvestorGroups\InvestorGroupResource;
use App\Services\Investment\InvestorGroupCycleService;
use App\Services\Investment\InvestorGroupService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ViewInvestorGroup extends ViewRecord
{
    protected static string $resource = InvestorGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (): bool => InvestorGroupResource::canEdit($this->record)),
            Action::make('startGroup')
                ->label(__('Start group'))
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn (): bool => $this->record->isOpen() && InvestorGroupResource::canManage())
                ->modalHeading(__('Start :group', ['group' => $this->record->name]))
                ->modalDescription(__('The group goes from Open to Running: no new members can join, Project 1 is created with the members and amounts as they are now, and a new project follows every :months months from the start date.', ['months' => $this->record->cycle_months]))
                ->schema([
                    DatePicker::make('running_since')->label(__('Running since'))->default(now())->maxDate(now())->required()
                        ->helperText(__('The day Project 1 starts. Every later project date is counted from this day.')),
                ])
                ->action(function (array $data, Action $action): void {
                    abort_unless(InvestorGroupResource::canManage(), 403);

                    try {
                        $project = app(InvestorGroupService::class)->startGroup($this->record, Carbon::parse($data['running_since']), auth()->user());
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title(collect($exception->errors())->flatten()->first())->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()
                        ->title(__('Group started. :project was created.', ['project' => $project->project_code]))
                        ->send();

                    $this->redirect(InvestmentProjectResource::getUrl('view', ['record' => $project]));
                }),
            Action::make('startNextProject')
                ->label(__('Start the next project now'))
                ->icon('heroicon-o-forward')
                ->color('warning')
                ->visible(fn (): bool => InvestorGroupResource::canManage() && $this->record->isRunning()
                    && app(InvestorGroupCycleService::class)->decide($this->record, InvestorGroupCycleService::today($this->record->company?->timezone))['action'] === InvestorGroupCycleService::BEHIND)
                ->requiresConfirmation()
                ->modalDescription(__('One or more whole cycles were missed, so the project did not start on its own. This closes the finished project and starts exactly one next project, dated from the group\'s start day. Check the dates first.'))
                ->action(function (): void {
                    abort_unless(InvestorGroupResource::canManage(), 403);

                    $result = app(InvestorGroupCycleService::class)->advance($this->record, InvestorGroupCycleService::today($this->record->company?->timezone), confirmCatchUp: true);

                    Notification::make()->success()
                        ->title($result['created'] ? __('Project :project started.', ['project' => $result['created']->project_code]) : __('Nothing to start.'))
                        ->send();
                }),
            Action::make('closeGroup')
                ->label(__('Close group'))
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->visible(fn (): bool => ! $this->record->isClosed() && InvestorGroupResource::canManage())
                ->requiresConfirmation()
                ->modalDescription(__('A closed group takes no new members and starts no new projects. Every project must be settled and every member must have taken their money out first.'))
                ->schema([
                    Textarea::make('close_reason')->label(__('Reason'))->rows(2)->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    abort_unless(InvestorGroupResource::canManage(), 403);

                    try {
                        app(InvestorGroupService::class)->closeGroup($this->record, (string) $data['close_reason'], auth()->user());
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title(collect($exception->errors())->flatten()->first())->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title(__('Group closed.'))->send();
                    $this->refreshFormData(['status', 'closed_on', 'close_reason', 'auto_cycle']);
                }),
        ];
    }
}
