<?php

namespace App\Notifications;

use App\Filament\Resources\DeploymentErrors\DeploymentErrorResource;
use App\Models\DeploymentError;
use App\Support\ClipboardCopy;
use App\Support\ModuleGuard;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Fired by App\Services\DeploymentErrorReporter whenever a deploy step
 * (currently: `php artisan deploy:migrate`, see App\Console\Commands\
 * DeployMigrate) fails. One of these lands per failed *step* -- if several
 * things go wrong in the same deploy, each gets its own notification and
 * therefore its own "Copy Error Log" button in the bell dropdown.
 *
 * Deliberately a dedicated class rather than reusing BusinessAlert: this
 * needs an extra clipboard-copy action that BusinessAlert's single generic
 * "view" action doesn't support.
 */
class DeploymentErrorAlert extends Notification
{
    /**
     * Kept out of the notifications table (which can be cleared/marked
     * read) -- the "Copy Error Log" button reads a truncated copy embedded
     * here, but the untruncated log always survives in the deployment_errors
     * table itself, viewable from the "View Full Log" action below.
     */
    protected const EMBEDDED_LOG_LIMIT = 6000;

    public function __construct(protected DeploymentError $error) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $embeddedLog = Str::limit(
            $this->error->details,
            static::EMBEDDED_LOG_LIMIT,
            "\n\n... (truncated -- use \"View Full Log\" for the complete copy)",
        );

        $message = FilamentNotification::make()
            ->title(str_starts_with($this->error->source, ModuleGuard::SOURCE_PREFIX)
                ? __('Module error (the rest of the app keeps working): :section', ['section' => Str::after($this->error->source, ModuleGuard::SOURCE_PREFIX)])
                : "Deploy error: {$this->error->source}")
            ->body(Str::limit($this->error->message, 200))
            ->danger()
            ->actions([
                Action::make('copy')
                    ->label('Copy Error Log')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->color('gray')
                    ->button()
                    ->alpineClickHandler(ClipboardCopy::alpineHandler($embeddedLog)),
                Action::make('view')
                    ->label('View Full Log')
                    ->url(DeploymentErrorResource::getUrl('view', ['record' => $this->error], panel: 'admin'))
                    ->button()
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();

        return [
            ...$message,
            'kind' => 'deployment-error',
            'deployment_error_id' => $this->error->getKey(),
            'source' => $this->error->source,
        ];
    }
}
