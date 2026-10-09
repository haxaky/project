<?php

namespace App\Services;

use App\Models\ChatGroup;
use App\Models\Story;
use App\Models\User;
use Chatify\Facades\ChatifyMessenger as Chatify;

class MessengerSocial
{
    public function groups(User $user)
    {
        return ChatGroup::whereHas('members', fn ($query) => $query->where('users.id', $user->id))
            ->withCount('members')->with('latestMessage')->latest('updated_at')->get()
            ->map(fn ($group) => [
                'id' => $group->id, 'name' => $group->name, 'owner_id' => $group->owner_id,
                'members_count' => $group->members_count,
                'last_message' => $group->latestMessage?->body ?: ($group->latestMessage ? 'Tệp đính kèm' : 'Bắt đầu trò chuyện nhóm'),
                'updated_at' => $group->updated_at->toIso8601String(),
                'info_url' => route('groups.show', $group),
                'messages_url' => route('groups.messages', $group),
            ])->values();
    }

    public function group(ChatGroup $group): array
    {
        $group->load('members:id,name,avatar');

        return [
            'id' => $group->id, 'name' => $group->name, 'owner_id' => $group->owner_id,
            'members' => $group->members->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'avatar' => Chatify::getUserAvatarUrl($user->avatar)]),
            'messages_url' => route('groups.messages', $group),
            'members_url' => route('groups.members', $group),
            'leave_url' => route('groups.leave', $group),
        ];
    }

    public function stories(User $user)
    {
        return Story::active()->with('user:id,name,avatar')->withCount('viewers')
            ->withExists(['viewers as viewed' => fn ($query) => $query->where('users.id', $user->id)])
            ->oldest()->get()->map(fn ($story) => [
                'id' => $story->id, 'user_id' => $story->user_id, 'user_name' => $story->user->name,
                'avatar' => Chatify::getUserAvatarUrl($story->user->avatar),
                'body' => $story->body, 'background' => $story->background,
                'media_url' => $story->media_path ? route('stories.media', $story) : null,
                'is_video' => str_starts_with($story->media_mime ?? '', 'video/'),
                'music_url' => $story->music_path ? route('stories.music', $story) : null,
                'music_title' => $story->music_title,
                'music_start' => $story->music_start, 'music_duration' => $story->music_duration,
                'created_at' => $story->created_at->toIso8601String(), 'expires_at' => $story->expires_at->toIso8601String(),
                'viewed' => $story->user_id === $user->id || (bool) $story->viewed,
                'views_count' => $story->user_id === $user->id ? $story->viewers_count : null,
                'view_url' => route('stories.view', $story), 'viewers_url' => route('stories.viewers', $story),
                'delete_url' => route('stories.destroy', $story),
            ])->values();
    }
}
