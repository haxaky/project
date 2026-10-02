<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200">Tài khoản cá nhân</h2>
    </x-slot>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100">
                <h1 class="text-2xl font-semibold">Xin chào, {{ auth()->user()->name }}</h1>
                <p class="mt-2 text-gray-600 dark:text-gray-400">{{ auth()->user()->email }}</p>
                <p class="mt-4">Đây là không gian cá nhân của bạn. Mở tin nhắn để trò chuyện hoặc lưu ghi chú cho chính mình.</p>
                <div class="flex flex-wrap gap-4 mt-6">
                    <a href="{{ route(config('chatify.routes.prefix')) }}" class="px-4 py-3 rounded-lg bg-blue-600 text-white">Mở tin nhắn</a>
                    <a href="{{ route('user', ['id' => auth()->id()]) }}" class="px-4 py-3 rounded-lg border border-gray-300">Ghi chú của tôi</a>
                    <a href="{{ route('profile.edit') }}" class="px-4 py-3 rounded-lg border border-gray-300">Chỉnh sửa hồ sơ</a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
