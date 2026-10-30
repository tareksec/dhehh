<?php

namespace App\Filament\Resources\SocialPosts\Pages;

use App\Filament\Resources\SocialPosts\SocialPostResource;
use App\Models\SocialPost;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateSocialPost extends CreateRecord
{
    protected static string $resource = SocialPostResource::class;

    /** A new post is always a draft with approval pending, whatever the form sent. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = SocialPost::STATUS_DRAFT;
        $data['approval_state'] = SocialPost::APPROVAL_PENDING;
        $data['publish_state'] = SocialPost::PUBLISH_IDLE;
        $data['created_by_user_id'] = Auth::id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
