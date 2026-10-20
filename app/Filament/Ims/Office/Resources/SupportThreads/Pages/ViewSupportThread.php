<?php

namespace App\Filament\Ims\Office\Resources\SupportThreads\Pages;

use App\Filament\Ims\Office\Resources\SupportThreads\SupportThreadResource;
use App\Ims\Support\SupportService;
use App\Models\SupportThread;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/** One conversation: the messages and the answer box. */
class ViewSupportThread extends ViewRecord
{
    protected static string $resource = SupportThreadResource::class;

    protected string $view = 'filament.ims.office.support-thread';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        app(SupportService::class)->markReadByStaff($this->record);
    }

    public function getTitle(): string
    {
        return (string) $this->record->subject;
    }

    protected function getHeaderActions(): array
    {
        $canReply = fn (): bool => auth()->user()?->hasPermission('ims.support.reply') ?? false;

        return [
            Action::make('reply')
                ->label(__('Answer'))
                ->icon('heroicon-o-paper-airplane')
                ->visible($canReply)
                ->schema([
                    Textarea::make('body')->label(__('Your answer'))->required()->rows(5)->maxLength(3000),
                    Toggle::make('close')->label(__('Close the question after this answer')),
                ])
                ->action(function (array $data): void {
                    app(SupportService::class)->staffReply($this->record, auth()->user(), (string) $data['body'], (bool) ($data['close'] ?? false));
                    Notification::make()->title(__('Answer sent'))->success()->send();
                    $this->record->refresh();
                }),
            Action::make('close')
                ->label(__('Close'))
                ->color('gray')
                ->visible(fn (): bool => $canReply() && $this->record->status === SupportThread::STATUS_OPEN)
                ->action(function (): void {
                    app(SupportService::class)->close($this->record, auth()->user());
                    $this->record->refresh();
                }),
        ];
    }
}
