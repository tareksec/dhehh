<?php

namespace App\Filament\Ims\Office\Resources\FeedPosts\Pages;

use App\Filament\Ims\Office\Resources\FeedPosts\FeedPostResource;
use App\Filament\Resources\Pages\TrashedRecords;

/** Feed posts that were deleted (owner request 2026-11-01): restore one, or delete it for good. */
class TrashedFeedPosts extends TrashedRecords
{
    protected static string $resource = FeedPostResource::class;
}
