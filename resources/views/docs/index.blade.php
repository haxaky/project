<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Swagger API — {{ config('app.name') }}</title>
    @vite('resources/js/swagger.js')
    <style>
        body { margin: 0; background: #fafafa; }
        .docs-header { padding: 20px max(20px, calc((100% - 1420px) / 2)); background: #172c43; color: white; font: 15px/1.6 system-ui, sans-serif; }
        .docs-header h1 { margin: 0 0 8px; font-size: 24px; }
        .docs-header p { margin: 6px 0; }
        .docs-header a { color: #9cdaff; margin-right: 18px; }
    </style>
</head>
<body>
    <header class="docs-header">
        <h1>Swagger API — {{ config('app.name') }}</h1>
        <p>Chọn API → Try it out → điền tham số → Execute.</p>
        <p>Chat giao diện: đăng nhập cùng trình duyệt; Swagger tự gửi cookie và CSRF.
            API Chatify: bấm Authorize và nhập Sanctum Bearer token.</p>
        <p>@auth Đang đăng nhập: {{ auth()->user()->name }} (ID: {{ auth()->id() }}). @else Bạn chưa đăng nhập. @endauth</p>
        <a href="{{ route('login') }}">Đăng nhập</a>
        <a href="{{ route('dashboard') }}">Trang cá nhân</a>
        <a href="{{ route('docs.openapi') }}" download="openapi.json">Tải OpenAPI JSON</a>
    </header>
    <div id="swagger-ui"
         data-spec-url="{{ route('docs.openapi', [], false) }}"
         data-csrf-url="{{ route('docs.csrf', [], false) }}"></div>
</body>
</html>
