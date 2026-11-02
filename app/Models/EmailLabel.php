<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A coloured label staff put on email threads (Wholesale, Returns ...); per company, shared by its mailboxes. */
class EmailLabel extends Model
{
    use BelongsToCompany;

    /** The colours a label may have (the names the Email page styles). */
    public const COLORS = ['blue', 'green', 'orange', 'purple', 'amber', 'red', 'gray'];

    protected $fillable = ['company_id', 'name', 'color'];

    public function threads(): BelongsToMany
    {
        return $this->belongsToMany(EmailThread::class, 'email_label_thread', 'label_id', 'thread_id');
    }
}
