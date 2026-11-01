<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CompanyFaq extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    protected $fillable = ['company_id', 'question', 'answer', 'keywords', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * True when any of the comma-separated keywords appears in the message —
     * the deterministic no-LLM shortcut for frequently asked questions.
     */
    public function matches(string $message): bool
    {
        $haystack = Str::lower($message);

        return collect(explode(',', (string) $this->keywords))
            ->map(fn (string $keyword): string => Str::lower(trim($keyword)))
            ->filter()
            ->contains(fn (string $keyword): bool => str_contains($haystack, $keyword));
    }
}
