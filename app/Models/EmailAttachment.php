<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file that came with, or goes with, an email message. The bytes are on the company's private storage
 * (never a public URL); a staff member of the same company downloads it through a controller.
 */
class EmailAttachment extends Model
{
    use BelongsToCompany;

    /** The most one message may carry in all, in bytes (the web server's 16 MB request limit, with room for the message). */
    public const MAX_TOTAL_BYTES = 12 * 1024 * 1024;

    protected $fillable = ['company_id', 'message_id', 'filename', 'mime', 'size', 'path', 'content_id', 'is_inline'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'is_inline' => 'boolean'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'message_id');
    }
}
