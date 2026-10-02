<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 text-gray-900 antialiased">
    <main class="min-h-screen flex items-center justify-center p-6">
        <section class="w-full max-w-3xl bg-white rounded-2xl shadow-sm p-8 sm:p-12">
            <x-application-logo class="h-16 w-16 mb-8" />
            <p class="text-sm font-semibold text-blue-600 mb-3">ĐỒ ÁN TỐT NGHIỆP CÁ NHÂN</p>
            <h1 class="text-3xl sm:text-4xl font-bold mb-5">{{ config('app.name') }}</h1>
            <p class="text-lg text-gray-600 leading-relaxed">Không gian trò chuyện và lưu trữ tin nhắn của bạn. Quản lý hồ sơ cá nhân, trao đổi tin nhắn và chia sẻ tệp trong một giao diện đơn giản.</p>
            @if (config('project.owner_name'))
                <p class="mt-5 text-gray-600">Người thực hiện: <strong>{{ config('project.owner_name') }}</strong></p>
            @endif
            <div class="flex flex-wrap gap-3 mt-8">
                @auth
                    <a class="px-5 py-3 rounded-lg bg-blue-600 text-white" href="{{ route('dashboard') }}">Vào tài khoản của tôi</a>
                @else
                    <a class="px-5 py-3 rounded-lg bg-blue-600 text-white" href="{{ route('register') }}">Tạo tài khoản cá nhân</a>
                    <a class="px-5 py-3 rounded-lg border border-gray-300" href="{{ route('login') }}">Đăng nhập</a>
                @endauth
            </div>
            <div class="grid sm:grid-cols-3 gap-5 mt-10 pt-8 border-t text-gray-600 text-sm">
                <p><strong class="block text-gray-900 mb-2">Hồ sơ cá nhân</strong>Tự đặt tên, email và mật khẩu của bạn.</p>
                <p><strong class="block text-gray-900 mb-2">Tin nhắn riêng</strong>Trò chuyện với các tài khoản đã đăng ký.</p>
                <p><strong class="block text-gray-900 mb-2">Ghi chú của tôi</strong>Lưu tin nhắn và tệp cho chính mình.</p>
            </div>
        </section>
    </main>
</body>
</html>
