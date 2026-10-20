<?php

namespace App\Ims\Transparency;

use App\Ims\Files\ImsFiles;
use App\Ims\Notifications\ImsNotifier;
use App\Models\Company;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\ProjectUpdate;
use App\Models\ProjectUpdatePhoto;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Project news for investors (IMS-V2/PRD.md FR-30): text, up to twelve
 * photos, a video link. Investors see it, and are told, only once "Show to
 * investors" is on.
 */
class ProjectUpdateService
{
    public function __construct(private ImsFiles $files, private ImsNotifier $notifier, private AuditLogService $audit) {}

    /**
     * @param  array{project_id: int, title: string, body?: string|null, video_url?: string|null, visible_to_investors?: bool}  $data
     * @param  list<UploadedFile|string>  $photos
     */
    public function create(array $data, array $photos, User $by): ProjectUpdate
    {
        $this->authorize($by);

        if (count($photos) > ProjectUpdate::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photos' => __('Add at most :count photos.', ['count' => ProjectUpdate::MAX_PHOTOS])]);
        }

        $project = InvestmentProject::query()->findOrFail($data['project_id']);
        $company = Company::query()->findOrFail($project->company_id);

        $update = DB::transaction(function () use ($data, $photos, $project, $company, $by): ProjectUpdate {
            $update = ProjectUpdate::query()->create([
                'company_id' => $project->company_id,
                'project_id' => $project->getKey(),
                'title' => trim($data['title']),
                'body' => $data['body'] ?? null,
                'video_url' => $data['video_url'] ?? null,
                'visible_to_investors' => (bool) ($data['visible_to_investors'] ?? false),
                'created_by' => $by->getKey(),
            ]);

            foreach (array_values($photos) as $sort => $photo) {
                ProjectUpdatePhoto::query()->create([
                    'company_id' => $project->company_id,
                    'update_id' => $update->getKey(),
                    'path' => $this->files->store($company, 'project-updates', $photo, ImsFiles::IMAGES, 'photos')['path'],
                    'sort' => $sort,
                ]);
            }

            $this->audit->record('ims.project_update_created', $update, null, ['project_id' => $project->getKey(), 'visible' => $update->visible_to_investors]);

            return $update;
        });

        if ($update->visible_to_investors) {
            $this->tellInvestors($update);
        }

        return $update;
    }

    public function show(ProjectUpdate $update, User $by): void
    {
        $this->authorize($by);

        if ($update->visible_to_investors) {
            return;
        }

        $update->forceFill(['visible_to_investors' => true])->save();
        $this->tellInvestors($update);
    }

    private function tellInvestors(ProjectUpdate $update): void
    {
        $project = $update->project;

        Investor::query()->whereIn('id', Investment::query()->where('project_id', $project->getKey())->pluck('investor_id'))->get()
            ->each(fn (Investor $investor) => $this->notifier->send('project_update', $investor, ['project' => (string) $project->name, 'title' => (string) $update->title]));
    }

    private function authorize(User $by): void
    {
        if (! $by->hasPermission('ims.updates.manage')) {
            throw ValidationException::withMessages(['project' => __('You do not have permission to post project updates.')]);
        }
    }
}
