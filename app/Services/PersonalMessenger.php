<?php

namespace App\Services;

use Chatify\ChatifyMessenger;

class PersonalMessenger extends ChatifyMessenger
{
    public function __construct()
    {
        if (config('chatify.transport') === 'pusher') {
            parent::__construct();
        }
    }

    public function push($channel, $event, $data)
    {
        return $this->pusher ? parent::push($channel, $event, $data) : null;
    }

    public function getUserAvatarUrl($user_avatar_name)
    {
        if (!$user_avatar_name || $user_avatar_name === config('chatify.user_avatar.default') ||
            !$this->storage()->exists(config('chatify.user_avatar.folder').'/'.$user_avatar_name)) {
            return asset('images/avatar.svg');
        }

        return parent::getUserAvatarUrl($user_avatar_name);
    }

    public function getUserWithAvatar($user)
    {
        $profile = clone $user;
        $profile->avatar = $this->getUserAvatarUrl($user->avatar);

        return $profile;
    }

    public function pusherAuth($requestUser, $authUser, $channelName, $socket_id)
    {
        abort_unless($this->pusher, 404);

        return parent::pusherAuth($requestUser, $authUser, $channelName, $socket_id);
    }
}
