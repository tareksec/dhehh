<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A step on a project's timeline (IMS-V2/PRD.md FR-31): ordered → shipped → arrived → selling → done. */
class ProjectMilestone extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'project_id', 'title', 'due_on', 'done_on', 'sort'];

    protected $casts = ['due_on' => 'date', 'done_on' => 'date'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(InvestmentProject::class, 'project_id');
    }

    public function isDone(): bool
    {
        return $this->done_on !== null;
    }
}
