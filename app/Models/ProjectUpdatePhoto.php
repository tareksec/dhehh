<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectUpdatePhoto extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'update_id', 'path', 'sort'];

    protected $hidden = ['path'];

    public function projectUpdate(): BelongsTo
    {
        return $this->belongsTo(ProjectUpdate::class, 'update_id');
    }
}
