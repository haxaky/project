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
        $this->social();

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
                ['name' => 'Chat nhóm & Story', 'description' => 'Cookie phiên + CSRF. Chat nhóm chỉ dành cho thành viên; story hiển thị với người dùng đã đăng nhập trong 24 giờ.'],
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

    private function social(): void
    {
        $memberIds = ['type' => 'array', 'minItems' => 1, 'maxItems' => 99, 'uniqueItems' => true, 'items' => ['type' => 'integer']];
        $message = $this->object([
            'id' => ['type' => 'integer'], 'body' => ['type' => 'string', 'nullable' => true],
            'user_id' => ['type' => 'integer', 'nullable' => true], 'user_name' => ['type' => 'string'],
            'avatar' => ['type' => 'string'], 'created_at' => ['type' => 'string', 'format' => 'date-time'],
            'attachment_url' => ['type' => 'string', 'nullable' => true], 'attachment_name' => ['type' => 'string', 'nullable' => true],
            'attachment_mime' => ['type' => 'string', 'nullable' => true],
        ]);
        $tag = 'Chat nhóm & Story';
        $group = $this->object([
            'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'owner_id' => ['type' => 'integer'],
            'members' => ['type' => 'array', 'items' => $this->object(['id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'avatar' => ['type' => 'string']])],
            'messages_url' => ['type' => 'string'], 'members_url' => ['type' => 'string'], 'leave_url' => ['type' => 'string'],
        ]);
        $story = $this->object([
            'id' => ['type' => 'integer'], 'user_id' => ['type' => 'integer'], 'user_name' => ['type' => 'string'], 'avatar' => ['type' => 'string'],
            'body' => ['type' => 'string', 'nullable' => true], 'background' => ['type' => 'string'], 'media_url' => ['type' => 'string', 'nullable' => true], 'is_video' => ['type' => 'boolean'],
            'music_url' => ['type' => 'string', 'nullable' => true], 'music_title' => ['type' => 'string', 'nullable' => true], 'music_start' => ['type' => 'integer'], 'music_duration' => ['type' => 'integer'],
            'viewed' => ['type' => 'boolean'], 'views_count' => ['type' => 'integer', 'nullable' => true],
            'created_at' => ['type' => 'string', 'format' => 'date-time'], 'expires_at' => ['type' => 'string', 'format' => 'date-time'],
            'view_url' => ['type' => 'string'], 'viewers_url' => ['type' => 'string'], 'delete_url' => ['type' => 'string'],
        ]);
        $this->operation('/groups', 'get', 'Danh sách nhóm trong Tin nhắn', $tag, [], [], $this->object(['groups' => ['type' => 'array', 'items' => $this->object([
            'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'owner_id' => ['type' => 'integer'], 'members_count' => ['type' => 'integer'],
            'last_message' => ['type' => 'string'], 'updated_at' => ['type' => 'string', 'format' => 'date-time'], 'info_url' => ['type' => 'string'], 'messages_url' => ['type' => 'string'],
        ])]]), 'sessionAuth', 'Accept: application/json trả dữ liệu; truy cập bằng trình duyệt chuyển về Tin nhắn.');
        $this->operation('/groups/{group}', 'get', 'Thông tin nhóm và thành viên', $tag, [], [], $this->object(['group' => $group]), 'sessionAuth');
        $this->operation('/groups', 'post', 'Tạo nhóm và mời thành viên', $tag,
            ['name' => ['type' => 'string', 'maxLength' => 100], 'members' => $memberIds], ['name', 'members'], $this->object(['group' => $group]), 'sessionAuth');
        $this->operation('/groups/{group}/members', 'post', 'Quản trị viên thêm thành viên (tối đa 100)', $tag,
            ['members' => $memberIds], ['members'], $this->object(['group' => $group]), 'sessionAuth');
        $this->operation('/groups/{group}/membership', 'delete', 'Rời nhóm; tự chuyển quyền quản trị nếu cần', $tag, [], [], $this->object(['left' => ['type' => 'boolean']]), 'sessionAuth');
        $this->operation('/groups/{group}/messages', 'get', 'Lấy tối đa 50 tin nhắn nhóm', $tag,
            ['after' => ['type' => 'integer', 'minimum' => 0], 'before' => ['type' => 'integer', 'minimum' => 1]], [],
            $this->object(['messages' => ['type' => 'array', 'items' => $message], 'has_more' => ['type' => 'boolean']]), 'sessionAuth',
            'Không truyền con trỏ: 50 tin mới nhất. before: tải tin cũ. after: polling tin mới, thứ tự tăng dần. Không dùng cả hai cùng lúc.');
        $this->operation('/groups/{group}/messages', 'post', 'Gửi chữ hoặc tệp trong nhóm', $tag,
            ['body' => ['type' => 'string', 'maxLength' => 5000], 'file' => ['type' => 'string', 'format' => 'binary']], [],
            $this->object(['message' => $message]), 'sessionAuth', 'Cần body hoặc file. Tệp tối đa 10 MB; ảnh, PDF, TXT, ZIP hoặc Office.', true);
        $this->paths['/groups/{group}/messages']['post']['responses']['201'] = $this->paths['/groups/{group}/messages']['post']['responses']['200'];
        unset($this->paths['/groups/{group}/messages']['post']['responses']['200']);
        $this->operation('/groups/{group}/messages/{message}/attachment', 'get', 'Mở/tải tệp nhóm (chỉ thành viên)', $tag, [], [], [], 'sessionAuth');
        $this->operation('/stories', 'get', 'Feed story cập nhật ngay trong Tin nhắn', $tag, [], [], $this->object(['stories' => ['type' => 'array', 'items' => $story]]), 'sessionAuth');
        $this->operation('/stories', 'post', 'Đăng story chữ, ảnh/video kèm nhạc trong 24 giờ', $tag,
            ['body' => ['type' => 'string', 'maxLength' => 1000], 'media' => ['type' => 'string', 'format' => 'binary'],
                'music' => ['type' => 'string', 'format' => 'binary'], 'music_token' => ['type' => 'string', 'format' => 'uuid'], 'music_title' => ['type' => 'string', 'maxLength' => 100],
                'music_start' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3600, 'default' => 0], 'music_duration' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 30, 'default' => 15],
                'background' => ['type' => 'string', 'enum' => ['indigo', 'rose', 'emerald', 'amber', 'slate']]], ['background'], $this->object(['stories' => ['type' => 'array', 'items' => $story]]), 'sessionAuth',
            'Cần body, media, music hoặc music_token. Không gửi đồng thời music và music_token. Ảnh/video tối đa 20 MB; music trực tiếp tối đa 10 MB và chịu giới hạn PHP. Giao diện tải nhạc tới 50 MB qua music-uploads, cắt tệp MP3 thật rồi gửi token. Nhạc mặc định cắt 15 giây; feed luôn music_start=0 cho tệp đã cắt. Feed JSON cập nhật ngay, không rời Tin nhắn.', true);
        $this->operation('/stories/music-uploads', 'post', 'Khởi tạo tải nhạc từng phần, tránh giới hạn PHP 2 MB', $tag,
            ['name' => ['type' => 'string', 'maxLength' => 255], 'size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 52428800],
                'start' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3600], 'duration' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 30]],
            ['name', 'size', 'start', 'duration'], $this->object(['upload_url' => ['type' => 'string'], 'finish_url' => ['type' => 'string'], 'cancel_url' => ['type' => 'string'], 'chunk_size' => ['type' => 'integer']]), 'sessionAuth', 'MP3/M4A/OGG/WAV; tối đa 3 lần tải dở mỗi tài khoản, hết hạn sau 1 giờ.');
        $this->operation('/stories/music-uploads/{upload}/chunks', 'post', 'Tải tuần tự từng phần nhạc 512 KB', $tag,
            ['offset' => ['type' => 'integer', 'minimum' => 0], 'chunk' => ['type' => 'string', 'format' => 'binary']], ['offset', 'chunk'],
            $this->object(['received' => ['type' => 'integer'], 'size' => ['type' => 'integer']]), 'sessionAuth', 'Offset phải bằng received; phần cuối đúng số byte còn lại. Chỉ chủ lần tải được gửi.', true);
        $this->operation('/stories/music-uploads/{upload}/finish', 'post', 'Cắt nhạc thành MP3 bằng FFmpeg rồi xóa bản gốc', $tag, [], [],
            $this->object(['music_token' => ['type' => 'string', 'format' => 'uuid'], 'music_duration' => ['type' => 'integer']]), 'sessionAuth', 'Cần tải đủ các phần. Token chỉ dùng một lần để đăng story của chính tài khoản tải lên.');
        $this->operation('/stories/music-uploads/{upload}', 'delete', 'Hủy lần tải nhạc và xóa tệp tạm', $tag, [], [], $this->object(['deleted' => ['type' => 'boolean']]), 'sessionAuth');
        $this->operation('/stories/{story}/media', 'get', 'Mở ảnh/video story còn hiệu lực', $tag, [], [], [], 'sessionAuth');
        $this->operation('/stories/{story}/music', 'get', 'Phát nhạc story còn hiệu lực', $tag, [], [], [], 'sessionAuth');
        $this->operation('/stories/{story}/view', 'post', 'Đánh dấu đã xem story', $tag, [], [], $this->object(['viewed' => ['type' => 'boolean']]), 'sessionAuth', 'Không tính chủ tin; mỗi người chỉ có một bản ghi xem.');
        $this->operation('/stories/{story}/viewers', 'get', 'Danh sách người xem (chỉ chủ story)', $tag, [], [],
            $this->object(['viewers' => ['type' => 'array', 'items' => $this->object([
                'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'viewed_at' => ['type' => 'string', 'format' => 'date-time'],
            ])]]), 'sessionAuth');
        $this->operation('/stories/{story}', 'delete', 'Xóa story và tệp media/nhạc của mình', $tag, [], [], $this->object(['deleted' => ['type' => 'boolean']]), 'sessionAuth');

        foreach ($this->paths as $path => &$methods) {
            foreach ($methods as $method => &$operation) {
                if (($operation['tags'][0] ?? '') !== $tag) {
                    continue;
                }
                foreach (['group', 'message', 'story', 'upload'] as $parameter) {
                    if (str_contains($path, '{'.$parameter.'}')) {
                        $operation['parameters'][] = ['name' => $parameter, 'in' => 'path', 'required' => true, 'schema' => $parameter === 'upload' ? ['type' => 'string', 'format' => 'uuid'] : ['type' => 'integer']];
                    }
                }
                $operation['responses']['403'] = $this->error('Không có quyền thực hiện thao tác.');
                $operation['responses']['404'] = $this->error('Không tìm thấy; story có thể đã hết hạn.');
                $operation['responses']['422'] = ['description' => 'Dữ liệu không hợp lệ.', 'content' => ['application/json' => ['schema' => $this->ref('ValidationError')]]];
                if (str_starts_with($path, '/stories/music-uploads')) {
                    $operation['responses']['410'] = $this->error('Lần tải nhạc hết hạn sau một giờ.');
                    $operation['responses']['409'] = $this->error('Phần nhạc không đúng thứ tự hoặc đã cắt xong.');
                    $operation['responses']['429'] = $this->error('Quá nhiều lần tải hoặc quá nhiều lần tải dở.');
                    $operation['responses']['503'] = $this->error('Máy chủ chưa có công cụ cắt nhạc hoặc đang bận.');
                    if ($path === '/stories/music-uploads' && $method === 'post') {
                        $operation['responses']['201'] = $operation['responses']['200'];
                        unset($operation['responses']['200']);
                    }

                    continue;
                }
                if ($method === 'get' && (str_ends_with($path, '/media') || str_ends_with($path, '/music') || str_ends_with($path, '/attachment'))) {
                    $operation['responses']['200'] = ['description' => 'Tệp nhị phân.', 'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]];
                } elseif (($method !== 'get' && ! str_ends_with($path, '/messages') && ! str_ends_with($path, '/view'))) {
                    if ($method === 'post' && in_array($path, ['/groups', '/stories'], true)) {
                        $operation['responses']['201'] = $operation['responses']['200'];
                        unset($operation['responses']['200']);
                    }
                    $operation['responses']['302'] = ['description' => 'Chỉ request HTML: chuyển về Tin nhắn; Accept: application/json trả JSON, không chuyển trang.'];
                }
            }
        }
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
