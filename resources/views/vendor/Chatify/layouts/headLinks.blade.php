<title>{{ config('chatify.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="id" content="{{ $id }}">
<meta name="messenger-color" content="{{ $messengerColor }}">
<meta name="messenger-theme" content="{{ $dark_mode }}">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="url" content="{{ url(config('chatify.routes.prefix')) }}" data-user="{{ Auth::id() }}">
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
<script src="{{ asset('js/chatify/font.awesome.min.js') }}"></script>
<script src="{{ asset('js/chatify/autosize.js') }}"></script>
<link href="{{ asset('css/chatify/style.css') }}" rel="stylesheet">
<link href="{{ asset('css/chatify/'.$dark_mode.'.mode.css') }}" rel="stylesheet">
@vite('resources/js/messenger.js')
<style>:root { --primary-color: {{ $messengerColor }}; } body { font-family: Arial, sans-serif; }</style>
