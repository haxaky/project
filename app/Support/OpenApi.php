<?php

namespace App\Support;

class OpenApi
{
    private array $paths = [];

    public function document(): array
    {
        $this->paths = [];
        $this->chat(false);
        $this->chat(true);
        $this->accounts();

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('app.name').' — API',
                'version' => '1.0.0',
                'description' => 'Tài liệu các endpoint dữ liệu và thao tác của ứng dụng Laravel/Chatify. '
                    .'Chat giao diện dùng phiên đăng nhập (cookie) và CSRF; nhiều trường phản hồi chứa HTML. '
                    .'API Chatify dùng Sanctum Bearer token và trả dữ liệu JSON. '
                    .'Đăng nhập/đăng ký hiện trả chuyển hướng HTTP 302, không cấp Bearer token. '
                    .'Các trang HTML thuần và endpoint debug không nằm trong tài liệu này. '
                    .'Try it out thực hiện thao tác thật trên dữ liệu của tài khoản đang xác thực.',
            ],
            'servers' => [['url' => '/', 'description' => 'Máy chủ đang mở Swagger']],
            'tags' => [
                ['name' => 'Chat giao diện', 'description' => 'Cookie phiên + CSRF. Đăng nhập cùng trình duyệt trước khi thử.'],
                ['name' => 'API Chatify', 'description' => 'Authorize bằng Sanctum token. Cookie đăng nhập giao diện không thay thế Bearer token trong cấu hình hiện tại.'],
                ['name' => 'Tài khoản', 'description' => 'Đăng nhập, đăng ký, mật khẩu và hồ sơ. Thành công thường trả HTTP 302.'],
            ],
            'paths' => $this->paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'Sanctum', 'description' => 'Nhập token thuần, không thêm tiền tố Bearer.'],
                    'sessionAuth' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => config('session.cookie'), 'description' => 'Đăng nhập ở /login cùng trình duyệt; trình duyệt tự gửi cookie. Không nhập cookie thủ công trong Authorize.'],
                ],
                'schemas' => $this->schemas(),
            ],
        ];
    }

    private function chat(bool $api): void
    {
        $prefix = '/'.trim(config($api ? 'chatify.api_routes.prefix' : 'chatify.routes.prefix'), '/');
        $tag = $api ? 'API Chatify' : 'Chat giao diện';
        $auth = $api ? 'bearerAuth' : 'sessionAuth';
        $id = ['id' => $this->integer('ID người dùng đối thoại; dùng ID của mình để ghi chú.')];
        $userId = ['user_id' => $this->integer('ID người dùng đối thoại.')];
        $pagination = [
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'default' => 30],
        ];
        $html = ['type' => 'string', 'description' => 'Đoạn HTML được giao diện chat hiển thị.'];
        $userList = ['type' => 'array', 'items' => $this->ref('User')];
        $count = ['type' => 'integer'];
        $flag = ['type' => 'integer', 'enum' => [0, 1]];
        $pageResponse = ['total' => $count, 'last_page' => $count];
        $add = function (string $path, string $method, string $summary, array $input, array $required, array $response, string $description = '', bool $multipart = false) use ($prefix, $tag, $auth) {
            $this->operation($prefix.'/'.$path, $method, $summary, $tag, $input, $required, $response, $auth, $description, $multipart);
        };

        $add('idInfo', 'post', $api ? 'Lấy tài khoản hiện tại' : 'Lấy thông tin người dùng', $api ? [] : $id, $api ? [] : ['id'],
            $api ? $this->ref('User') : $this->object([
                'favorite' => ['type' => 'boolean'],
                'fetch' => ['allOf' => [$this->ref('User')], 'nullable' => true],
                'user_avatar' => ['type' => 'string', 'nullable' => true],
            ]),
            $api ? 'Controller API hiện trả trực tiếp auth()->user(); không xử lý id/type và không trả favorite/fetch/user_avatar.' : 'Người dùng không tồn tại: fetch và user_avatar là null.');

        $send = $id + [
            'message' => ['type' => 'string', 'example' => 'Xin chào!', 'description' => 'Nội dung tin nhắn.'.($api ? '' : ' Bắt buộc khi không gửi file.')],
            'temporaryMsgId' => ['type' => 'string', 'example' => 'temp_1', 'description' => 'ID tạm phía client, được trả lại trong tempID.'],
            'file' => ['type' => 'string', 'format' => 'binary', 'description' => 'Định dạng: '.implode(', ', array_merge(config('chatify.attachments.allowed_images'), config('chatify.attachments.allowed_files'))).'. Kích thước nhỏ hơn '.config('chatify.attachments.max_upload_size').' MB; còn chịu giới hạn upload của PHP.'],
        ];
        if (! $api) {
            $send['message']['maxLength'] = 5000;
        } else {
            $send['type'] = ['type' => 'string', 'example' => 'user', 'description' => 'Tham số controller API đọc; model tin nhắn hiện không lưu type.'];
        }
        $add('sendMessage', 'post', 'Gửi tin nhắn hoặc tệp', $send, ['id'], $this->object([
            'status' => ['type' => 'string', 'example' => '200'],
            'error' => $this->object(['status' => $flag, 'message' => ['type' => 'string', 'nullable' => true]]),
            'message' => $api ? ['oneOf' => [$this->ref('ParsedMessage'), ['type' => 'array', 'items' => $this->ref('ParsedMessage'), 'maxItems' => 0]]] : $html,
            'tempID' => ['type' => 'string', 'nullable' => true],
        ]), 'Lỗi định dạng/kích thước tệp có thể trả HTTP 200 với error.status = 1. '.($api ? 'Controller API của thư viện không có cùng validation id/message như controller giao diện.' : 'id phải tồn tại. message hoặc file phải được cung cấp.'), true);

        $add('fetchMessages', 'post', 'Lấy lịch sử tin nhắn', $id + $pagination, ['id'], $this->object($pageResponse + [
            'last_message_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true],
            'messages' => $api ? ['type' => 'array', 'items' => $this->ref('Message')] : $html,
        ]));
        $add('makeSeen', 'post', 'Đánh dấu tin nhắn đã đọc', $id, ['id'], $this->object(['status' => $flag]));
        $add('getContacts', 'get', 'Lấy danh sách cuộc trò chuyện', $pagination, [], $this->object($pageResponse + ['contacts' => $api ? $userList : $html]));
        $add('search', 'get', 'Tìm người dùng theo tên', ['input' => ['type' => 'string', 'example' => 'An']] + $pagination, ['input'], $this->object($pageResponse + [
            'records' => $api ? $userList : ['type' => 'string', 'nullable' => true, 'description' => 'HTML danh sách tìm kiếm.'],
        ]));
        $add('star', 'post', 'Bật/tắt yêu thích người dùng', $userId, ['user_id'], $this->object(['status' => $flag]), 'Mỗi lần gọi đảo trạng thái yêu thích.');
        $add('favorites', 'post', 'Lấy danh sách yêu thích', [], [], $this->object($api ? [
            'total' => $count, 'favorites' => ['type' => 'array', 'items' => $this->ref('Favorite')],
        ] : [
            'count' => $count, 'favorites' => ['oneOf' => [$html, ['type' => 'integer', 'enum' => [0]]]],
        ]));
        $add('shared', 'post', 'Lấy ảnh đã chia sẻ', $userId, ['user_id'], $this->object([
            'shared' => $api ? ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tên tệp ảnh lưu trữ theo controller hiện tại.'] : $html,
        ]));
        $add('deleteConversation', 'post', 'Xóa cuộc trò chuyện', $id, ['id'], $this->object(['deleted' => $flag]), 'Xóa tin nhắn và tệp của cuộc trò chuyện theo hành vi Chatify.');
        $add('updateSettings', 'post', 'Đổi giao diện hoặc ảnh đại diện', [
            'dark_mode' => ['type' => 'string', 'enum' => ['dark', 'light']],
            'messengerColor' => ['type' => 'string', 'example' => '#2180f3'],
            'avatar' => ['type' => 'string', 'format' => 'binary', 'description' => 'Ảnh '.implode(', ', config('chatify.attachments.allowed_images')).'; nhỏ hơn '.config('chatify.attachments.max_upload_size').' MB.'],
        ], [], $this->object([
            'status' => $flag, 'error' => $flag, 'message' => ['oneOf' => [['type' => 'string'], ['type' => 'integer', 'enum' => [0]]]],
        ]), 'status phản ánh cập nhật avatar; có thể là 0 khi chỉ đổi màu/chế độ. Kiểm tra error để biết lỗi upload.', true);
        $add('setActiveStatus', 'post', 'Cập nhật trạng thái hoạt động', ['status' => $flag], ['status'], $this->object(['status' => $count]), 'Input status > 0 là online. Response status là số dòng được cập nhật.');
        $add('chat/auth', 'post', 'Xác thực kênh Pusher', [
            'channel_name' => ['type' => 'string', 'example' => 'private-chatify.1'],
            'socket_id' => ['type' => 'string', 'example' => '1234.5678'],
        ], ['channel_name', 'socket_id'], $this->object([
            'auth' => ['type' => 'string'], 'channel_data' => ['type' => 'string'],
        ]), 'Chỉ dùng khi CHAT_TRANSPORT=pusher. Chế độ polling mặc định trả 404.');
        $this->paths[$prefix.'/chat/auth']['post']['responses']['404'] = $this->error('Pusher chưa được bật.');
        $this->paths[$prefix.'/chat/auth']['post']['responses']['403'] = $this->error('Không được phép xác thực kênh.');

        $downloadPath = $prefix.'/download/{fileName}';
        $this->operation($downloadPath, 'get', $api ? 'Lấy URL tải tệp' : 'Tải tệp đính kèm', $tag, [], [],
            $api ? $this->object(['file_name' => ['type' => 'string'], 'download_path' => ['type' => 'string']]) : ['type' => 'string', 'format' => 'binary'], $auth);
        $this->paths[$downloadPath]['get']['parameters'] = [
            ['name' => 'fileName', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Tên lưu trữ (UUID + đuôi), không phải tên tệp gốc.'],
        ];
        $this->paths[$downloadPath]['get']['responses']['404'] = $this->error('Tệp không tồn tại.');
        if (! $api) {
            $this->paths[$downloadPath]['get']['responses']['200']['content'] = [
                'application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']],
            ];
            $add('poll', 'post', 'Đồng bộ tin nhắn bằng polling', $id + [
                'limit' => ['type' => 'integer', 'minimum' => 30, 'maximum' => 10000, 'example' => 30],
            ], ['id', 'limit'], $this->object([
                'total' => $count, 'signature' => ['type' => 'string', 'description' => 'SHA-256 của dữ liệu tin nhắn để phát hiện thay đổi.'], 'messages' => $html,
            ]));
            $add('deleteMessage', 'post', 'Xóa một tin nhắn', [
                'id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'UUID tin nhắn.'],
            ], ['id'], $this->object(['deleted' => $flag]));
            $add('updateContacts', 'post', 'Lấy HTML mới của một liên hệ', $userId, ['user_id'], $this->object(['contactItem' => $html]), 'Trả 401 nếu user_id không tồn tại.');
        }
    }

    private function accounts(): void
    {
        $email = ['type' => 'string', 'format' => 'email', 'example' => 'ban@example.com', 'maxLength' => 255];
        $password = ['type' => 'string', 'format' => 'password', 'minLength' => 8];
        $confirmation = ['password' => $password, 'password_confirmation' => $password];
        $definitions = [
            ['/login', 'post', 'Đăng nhập', ['email' => $email, 'password' => ['type' => 'string', 'format' => 'password'], 'remember' => ['type' => 'boolean', 'default' => false]], ['email', 'password'], null],
            ['/register', 'post', 'Đăng ký tài khoản', ['name' => ['type' => 'string', 'maxLength' => 255], 'email' => $email] + $confirmation, ['name', 'email', 'password', 'password_confirmation'], null],
            ['/logout', 'post', 'Đăng xuất', [], [], 'sessionAuth'],
            ['/forgot-password', 'post', 'Yêu cầu đặt lại mật khẩu', ['email' => $email], ['email'], null],
            ['/reset-password', 'post', 'Đặt lại mật khẩu', ['token' => ['type' => 'string'], 'email' => $email] + $confirmation, ['token', 'email', 'password', 'password_confirmation'], null],
            ['/confirm-password', 'post', 'Xác nhận mật khẩu', ['password' => ['type' => 'string', 'format' => 'password']], ['password'], 'sessionAuth'],
            ['/password', 'put', 'Đổi mật khẩu', ['current_password' => ['type' => 'string', 'format' => 'password']] + $confirmation, ['current_password', 'password', 'password_confirmation'], 'sessionAuth'],
            ['/profile', 'patch', 'Cập nhật hồ sơ', ['name' => ['type' => 'string', 'maxLength' => 255], 'email' => $email], [], 'sessionAuth'],
            ['/profile', 'delete', 'Xóa tài khoản', ['password' => ['type' => 'string', 'format' => 'password']], ['password'], 'sessionAuth'],
            ['/email/verification-notification', 'post', 'Gửi lại email xác minh', [], [], 'sessionAuth'],
        ];
        foreach ($definitions as [$path, $method, $summary, $input, $required, $auth]) {
            $this->operation($path, $method, $summary, 'Tài khoản', $input, $required, [], $auth,
                'Endpoint web: thành công trả 302 và cập nhật phiên/flash nếu có. Với Accept: application/json, lỗi validation trả 422; lỗi broker đặt lại mật khẩu vẫn có thể trả 302. Trình duyệt tự theo chuyển hướng nên Swagger có thể hiển thị HTML của trang đích.');
            unset($this->paths[$path][$method]['responses']['200']);
            $this->paths[$path][$method]['responses']['302'] = [
                'description' => 'Chuyển hướng sau xử lý.',
                'headers' => ['Location' => ['description' => 'URL đích.', 'schema' => ['type' => 'string']]],
            ];
        }
        $this->operation('/api/user', 'get', 'Lấy tài khoản theo Bearer token', 'Tài khoản', [], [], $this->ref('User'), 'bearerAuth');
        $this->operation('/sanctum/csrf-cookie', 'get', 'Khởi tạo cookie CSRF', 'Tài khoản', [], [], [], null, 'Trả cookie XSRF-TOKEN và cookie phiên. Không đăng nhập và không cấp Bearer token.');
        unset($this->paths['/sanctum/csrf-cookie']['get']['responses']['200']);
        $this->paths['/sanctum/csrf-cookie']['get']['responses']['204'] = ['description' => 'Cookie CSRF đã được gửi.'];

        $verify = '/verify-email/{id}/{hash}';
        $this->operation($verify, 'get', 'Xác minh email bằng liên kết đã ký', 'Tài khoản', [], [], [], 'sessionAuth', 'Dùng URL đã ký từ email, gồm expires và signature; tự điền id/hash không tạo được chữ ký hợp lệ.');
        $this->paths[$verify]['get']['parameters'] = [
            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ['name' => 'hash', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
            ['name' => 'expires', 'in' => 'query', 'schema' => ['type' => 'integer']],
            ['name' => 'signature', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
        ];
        unset($this->paths[$verify]['get']['responses']['200']);
        $this->paths[$verify]['get']['responses']['302'] = ['description' => 'Chuyển đến dashboard?verified=1.'];
        $this->paths[$verify]['get']['responses']['403'] = $this->error('Chữ ký hoặc tài khoản không hợp lệ.');
    }

    private function operation(string $path, string $method, string $summary, string $tag, array $input, array $required, array $response, ?string $auth, string $description = '', bool $multipart = false): void
    {
        $operation = [
            'tags' => [$tag], 'summary' => $summary,
            'operationId' => $method.'_'.trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $path), '_'),
            'description' => $description,
            'security' => $auth ? [[$auth => []]] : [],
            'responses' => ['200' => ['description' => 'Thành công.', 'content' => ['application/json' => ['schema' => $response ?: $this->object([])]]]],
        ];
        if ($input) {
            if ($method === 'get') {
                foreach ($input as $name => $schema) {
                    $operation['parameters'][] = ['name' => $name, 'in' => 'query', 'required' => in_array($name, $required, true), 'schema' => $schema];
                }
            } else {
                $schema = $this->object($input, $required);
                $content = $multipart ? ['multipart/form-data' => ['schema' => $schema]] : [
                    'application/json' => ['schema' => $schema], 'application/x-www-form-urlencoded' => ['schema' => $schema],
                ];
                $operation['requestBody'] = ['required' => count($required) > 0, 'content' => $content];
            }
        }
        if ($auth) {
            $operation['responses']['401'] = $this->error('Chưa xác thực hoặc token không hợp lệ (Accept: application/json).');
        }
        if ($method !== 'get') {
            if ($auth !== 'bearerAuth') {
                $operation['responses']['419'] = $this->error('CSRF token thiếu/hết hạn. Swagger tự lấy token mới trong cùng phiên.');
            }
            // Only these controllers actually validate input; vendor endpoints do not.
            if ($tag === 'Tài khoản' || str_ends_with($path, '/poll') || ($tag === 'Chat giao diện' && str_ends_with($path, '/sendMessage'))) {
                $operation['responses']['422'] = ['description' => 'Dữ liệu không hợp lệ.', 'content' => ['application/json' => ['schema' => $this->ref('ValidationError')]]];
            }
        }
        if ($auth === 'bearerAuth' || str_contains($path, 'verification')) {
            $operation['responses']['429'] = $this->error('Vượt giới hạn request.');
        }
        $this->paths[$path][$method] = $operation;
    }

    private function schemas(): array
    {
        $nullableText = ['type' => 'string', 'nullable' => true];
        $timestamp = ['type' => 'string', 'format' => 'date-time'];
        $flag = ['oneOf' => [['type' => 'boolean'], ['type' => 'integer', 'enum' => [0, 1]]]];
        $message = [
            'id' => ['type' => 'string', 'format' => 'uuid'], 'from_id' => ['type' => 'integer'], 'to_id' => ['type' => 'integer'],
            'seen' => $flag, 'created_at' => $timestamp,
        ];

        return [
            'User' => $this->object([
                'id' => ['type' => 'integer', 'example' => 1], 'name' => ['type' => 'string', 'example' => 'Nguyễn An'],
                'email' => ['type' => 'string', 'format' => 'email', 'example' => 'ban@example.com'],
                'email_verified_at' => $timestamp + ['nullable' => true], 'avatar' => ['type' => 'string'],
                'active_status' => ['type' => 'integer'], 'dark_mode' => ['type' => 'integer'], 'messenger_color' => $nullableText,
                'created_at' => $timestamp, 'updated_at' => $timestamp, 'max_created_at' => $timestamp,
            ]),
            'Message' => $this->object($message + [
                'body' => $nullableText, 'attachment' => $nullableText + ['description' => 'Chuỗi JSON {new_name, old_name}, hoặc null.'], 'updated_at' => $timestamp,
            ]),
            'ParsedMessage' => $this->object($message + [
                'message' => ['type' => 'string'], 'timeAgo' => ['type' => 'string'], 'isSender' => ['type' => 'boolean'],
                'attachment' => $this->object(['file' => $nullableText, 'title' => $nullableText, 'type' => ['type' => 'string', 'nullable' => true, 'enum' => ['image', 'file', null]]]),
            ]),
            'Favorite' => $this->object([
                'id' => ['type' => 'string', 'format' => 'uuid'], 'user_id' => ['type' => 'integer'], 'favorite_id' => ['type' => 'integer'],
                'user' => ['allOf' => [$this->ref('User')], 'nullable' => true], 'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]),
            'Error' => $this->object(['message' => ['type' => 'string']]),
            'ValidationError' => $this->object([
                'message' => ['type' => 'string'], 'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ]),
        ];
    }

    private function object(array $properties, array $required = []): array
    {
        $schema = ['type' => 'object'];
        if ($properties) {
            $schema['properties'] = $properties;
        }
        if ($required) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    private function ref(string $name): array
    {
        return ['$ref' => '#/components/schemas/'.$name];
    }

    private function integer(string $description): array
    {
        return ['type' => 'integer', 'example' => 1, 'description' => $description];
    }

    private function error(string $description): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => $this->ref('Error')]]];
    }
}
