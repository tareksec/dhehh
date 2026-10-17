<?php

namespace App\Filament\Forms\Components;

use App\Support\CompanyMedia;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only preview of a record's `video_path` (Product / Offer), with a
 * "Remove video" hint action. Deliberately not an upload control: the only
 * writer of `video_path` is GeneratedVideoAttacher (12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md
 * §6.4), so video never goes through the browser-upload pipeline. Hidden
 * until a video is attached; never dehydrated.
 */
class AttachedVideoPreview extends Field
{
    protected string $view = 'filament.forms.components.attached-video-preview';

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Video'));
        $this->dehydrated(false);
        $this->visible(fn (?Model $record): bool => filled($record?->getAttribute('video_path')));
        $this->helperText(__('Added from AI Tools → Video Generation.'));
        $this->hintAction(
            Action::make('removeVideo')
                ->label(__('Remove video'))
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('Remove this video?'))
                ->modalDescription(__('It stops playing on the storefront. The clip itself stays in Video Generation, so you can attach it again later.'))
                ->action(function (?Model $record): void {
                    if ($record === null) {
                        return;
                    }

                    $record->forceFill(['video_path' => null])->save();

                    Notification::make()->title(__('Video removed'))->success()->send();
                }),
        );
    }

    public function getVideoUrl(): ?string
    {
        $record = $this->getRecord();

        return $record === null ? null : CompanyMedia::publicUrl($record->getAttribute('video_path'), $record);
    }
}
