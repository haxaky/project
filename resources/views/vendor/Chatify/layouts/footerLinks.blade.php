<script>
    window.chatify = {{ Illuminate\Support\Js::from([
        'name' => config('chatify.name'),
        'sounds' => config('chatify.sounds'),
        'allowedImages' => config('chatify.attachments.allowed_images'),
        'allowedFiles' => config('chatify.attachments.allowed_files'),
        'maxUploadSize' => Chatify::getMaxUploadSize(),
        'transport' => config('chatify.transport'),
        'pusher' => [
            'key' => config('chatify.pusher.key'),
            'debug' => config('chatify.pusher.debug'),
            'options' => config('chatify.pusher.options'),
        ],
        'pusherAuthEndpoint' => route('pusher.auth'),
        'pollEndpoint' => route('messages.poll'),
    ]) }};
    window.chatify.allAllowedExtensions = chatify.allowedImages.concat(chatify.allowedFiles);
</script>
