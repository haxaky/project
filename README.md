# Chat Cá Nhân

Ứng dụng trò chuyện phục vụ đồ án tốt nghiệp cá nhân: đăng ký/đăng nhập, hồ sơ cá nhân, nhắn tin cá nhân/nhóm, gửi tệp, story 24 giờ, yêu thích và ghi chú cho chính mình.

## Chạy trên máy cá nhân

Yêu cầu: PHP 8.1 trở lên có PDO SQLite, Composer, Node.js và npm.

```sh
composer install
cp .env.example .env
touch database/personal.sqlite
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
npm ci
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Nếu `.env` đã tồn tại thì giữ tệp đó, không sao chép đè. Mở http://127.0.0.1:8000 và chọn **Tạo tài khoản cá nhân**. Seeder không tạo tài khoản mặc định hoặc mật khẩu dùng chung. Dữ liệu nằm trong `database/personal.sqlite`, không dùng cơ sở dữ liệu MySQL của bản cũ.

## Chat nhóm và Story

Sau khi cập nhật mã nguồn, chạy `php artisan migrate` và `npm run build`. Tất cả nằm trong **Tin nhắn**: bấm biểu tượng nhóm ở đầu danh sách để tạo nhóm, chọn nhóm ngay trong danh sách trò chuyện và sử dụng cùng khung chat/ô nhập với nhắn tin cá nhân. Không có trang chat nhóm hoặc story riêng; URL cũ tự chuyển về Tin nhắn.

- **Chat nhóm**: đặt tên và chọn ít nhất một tài khoản khác để mời; nhóm tối đa 100 thành viên. Gửi chữ/tệp tối đa 10 MB, tự cập nhật mỗi 2 giây và tải tin nhắn cũ. Chỉ thành viên đọc được tin nhắn/tải tệp. Quản trị viên thêm người; rời nhóm tự chuyển quyền quản trị, nhóm trống tự xóa.
- **Story**: đăng chữ (tối đa 1.000 ký tự), chọn màu nền hoặc tải ảnh/video tối đa 20 MB (JPG, PNG, GIF, WebP, MP4, WebM). Mọi tài khoản đã đăng nhập có thể xem; không có danh sách bạn bè hay bộ lọc đối tượng trong phiên bản này.
- Story nằm ở dải avatar phía trên danh sách trò chuyện, tự cập nhật mỗi 5 giây khi đang mở Tin nhắn. Avatar người có story được viền; bấm avatar để xem tin, bấm tên/dòng trò chuyện để nhắn tin. Đăng/xem story bằng hộp nổi, không rời màn hình chat. Có xem trước, chuyển tin, tạm dừng, viền tin chưa xem và danh sách người xem chỉ dành cho chủ tin; chủ tin có thể xóa. Story hết 24 giờ tự ẩn, đường dẫn media/nhạc cũng hết hiệu lực.
- **Nhạc story**: chọn nguyên bài MP3, M4A, OGG hoặc WAV (tối đa 50 MB); ứng dụng tải từng phần 512 KB rồi **cắt thành tệp MP3 thật**, mặc định 15 giây từ đầu bài. Có thể chọn điểm bắt đầu và độ dài 5–30 giây; đoạn sát cuối bài chỉ giữ phần còn lại. Có tiến độ tải và trạng thái cắt nhạc. Chỉ lưu đoạn đã cắt, xóa bản gốc; tệp tải dở hết hạn sau một giờ và được dọn bằng `stories:prune`. Không cần tăng giới hạn upload PHP 2 MB cho nhạc trong giao diện. API upload trực tiếp `music` vẫn giới hạn 10 MB và chịu giới hạn PHP; giao diện dùng `music_token` qua các endpoint `/stories/music-uploads` để nhận tệp lớn. Có thể kết hợp chữ/ảnh/video hoặc đăng riêng nhạc. Nhạc tạm dừng cùng story và dừng khi chuyển/đóng tin; nếu trình duyệt chặn tự phát, bấm **Bật nhạc**. Chỉ tải nhạc bạn có quyền sử dụng; không có kho nhạc hay đồng bộ tài khoản Facebook.
- Máy chủ cần **FFmpeg và FFprobe** trong `PATH` (máy hiện tại đã có). Có thể đặt đường dẫn tuyệt đối qua `FFMPEG_BINARY` và `FFPROBE_BINARY` trong `.env`; nếu đã cache cấu hình, chạy `php artisan config:clear`. Nhạc được chuyển thành MP3 stereo 160 kbps. Các phần tải và đoạn chờ đăng nằm trên disk `local`, chỉ tài khoản tải lên được dùng token; token chỉ dùng một lần và hết hạn sau một giờ.
- Tệp nhóm/story lưu trên disk `local`, không công khai qua `/storage`. Story hết hạn được dọn bằng `php artisan stories:prune`; có lịch dọn mỗi giờ nếu chạy `php artisan schedule:work` hoặc cấu hình scheduler trên server. Không chạy scheduler vẫn tự ẩn story đúng hạn nhưng chưa xóa tệp cũ.
- Với ảnh/video 20 MB, PHP/server vẫn cần `upload_max_filesize=20M` và `post_max_size=35M` (hoặc lớn hơn), rồi khởi động lại PHP. Nhạc trong giao diện tải riêng từng phần 512 KB nên không cần tăng hai giới hạn này. Giới hạn validation không vượt qua giới hạn upload của PHP/server.

Kịch bản demo: tạo ba tài khoản, lập nhóm và gửi tin từ hai trình duyệt; đăng story chữ/ảnh, dùng tài khoản khác xem, rồi mở danh sách người xem bằng tài khoản chủ story. Các endpoint thao tác có trong Swagger ở `/docs`.

## Cá nhân hóa

Điền các giá trị sau trong `.env`, rồi chạy `php artisan config:clear`:

```dotenv
APP_NAME="Tên ứng dụng của bạn"
PROJECT_OWNER_NAME="Họ tên của bạn"
PROJECT_OWNER_EMAIL="Email của bạn"
```

Tên người thực hiện xuất hiện trên trang giới thiệu. Email đăng nhập và mật khẩu được nhập riêng khi đăng ký; đổi tên/email tài khoản trong **Hồ sơ cá nhân**. `PROJECT_OWNER_EMAIL` chỉ là thông tin cấu hình, không tự tạo tài khoản và không công khai email.

## Kịch bản demo

### Nhắn tin từ hai máy cùng mạng

Chỉ chạy server trên một máy. Hai máy phải cùng Wi-Fi/LAN và truy cập cùng địa chỉ server; không chạy hai bản dự án với hai database riêng.

```sh
php artisan serve --host=0.0.0.0 --port=8001
```

Trên macOS, dùng `ipconfig getifaddr en0` để xem IP Wi-Fi của máy chạy server. Cả hai máy mở `http://<IP-may-server>:8001`, đăng ký/đăng nhập hai tài khoản khác nhau, rồi tìm tên người còn lại trong **Tin nhắn**. Tin nhắn tự cập nhật mỗi khoảng 2 giây. Máy thứ hai chỉ cần trình duyệt. Giữ máy server chạy trong suốt buổi demo. Nếu máy thứ hai không mở được trang, kiểm tra firewall cho phép PHP/cổng 8001 và Wi-Fi không bật tính năng cách ly thiết bị.

### Thao tác trình diễn

1. Tạo tài khoản của bạn và kiểm tra thông tin cá nhân.
2. Mở **Ghi chú của tôi** để lưu tin nhắn hoặc tệp cho chính mình.
3. Tạo thêm một tài khoản do bạn quản lý trong cửa sổ ẩn danh; tìm theo tên ở ô **Tìm người dùng** để demo nhắn tin hai chiều.
4. Demo chia sẻ tệp, trạng thái đã đọc, yêu thích, đổi ảnh đại diện, giao diện tối, xóa tin nhắn và đăng xuất.

Mặc định chat dùng HTTP polling mỗi 2 giây, không cần tài khoản Pusher. Thư viện giao diện được đóng gói local. Chỉ báo đang gõ và trạng thái online qua presence cần Pusher; chế độ local không phát những sự kiện đó. Lịch sử tin nhắn có thể tải qua phân trang.

Để dùng Pusher của riêng bạn, đặt `CHAT_TRANSPORT=pusher` và điền `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, `PUSHER_APP_CLUSTER`. Secret chỉ nằm ở máy chủ. Mặc định `MAIL_MAILER=log`; muốn gửi email đặt lại mật khẩu thật cần cấu hình SMTP của bạn.

## Swagger / OpenAPI

Sau khi `npm ci` và `npm run build`, chạy ứng dụng và mở **http://127.0.0.1:8000/docs**.
Trang Swagger dùng tài nguyên local, có tìm kiếm, schema request/response và **Try it out**.

- **Chat giao diện** (`/messages/...`): đăng nhập ở `/login` bằng cùng trình duyệt rồi mở lại Swagger. Cookie được gửi tự động; Swagger lấy CSRF mới trước mỗi thao tác nên vẫn hoạt động sau đăng nhập/đăng xuất. Các trường `messages`, `contacts`, `records`, `shared` thường chứa HTML.
- **API Chatify** (`/messages/api/...`) và `/api/user`: bấm **Authorize**, nhập Sanctum token thuần. Cấu hình API hiện tại yêu cầu Bearer token; cookie của giao diện không đủ. Đăng nhập/đăng ký hiện trả HTTP 302, không cấp token. Nếu cần token cho tài khoản của mình, dùng `php artisan tinker` rồi `$user = App\Models\User::where('email', 'email-cua-ban')->firstOrFail();` và `$user->createToken('swagger')->plainTextToken;`. Giữ token riêng tư.
- **Tài khoản**: tài liệu ghi đúng chuyển hướng 302 và lỗi validation 422. Trình duyệt có thể tự theo chuyển hướng và hiển thị HTML của trang đích.

Swagger gọi API thật; gửi/xóa tin nhắn, đổi mật khẩu hoặc xóa tài khoản sẽ tác động đến dữ liệu đang dùng. Pusher auth trả 404 khi dùng polling. API `/messages/api/idInfo` của thư viện hiện trả tài khoản đang xác thực; tài liệu thể hiện đúng hành vi này.

Tải JSON bằng liên kết trên trang hoặc xuất tệp để nhập vào Postman/Swagger Editor:

```sh
php artisan swagger:export
```

Tệp xuất nằm ở `docs/openapi.json`; bản trực tiếp ở `/docs/openapi.json` luôn dùng cấu hình hiện tại, bao gồm prefix Chatify. Khi thay API/schema, cập nhật `app/Support/OpenApi.php`, chạy lại lệnh xuất và kiểm thử.

## Kiểm tra

```sh
php artisan test
npm run build
```

Kiểm thử dùng SQLite trong bộ nhớ, không xóa dữ liệu demo. Không đưa `.env`, database, session, log, ảnh đại diện hoặc tệp đính kèm cá nhân lên Git.

Các dependency kế thừa vẫn dùng phiên bản cũ và Composer/npm audit báo các lỗ hổng đã biết. Bản hiện tại phục vụ demo local; cần nâng cấp và kiểm tra dependency trước khi triển khai lên Internet.

## Nguồn thư viện

Dự án được tùy chỉnh từ ứng dụng Laravel/Chatify có sẵn. Laravel, Chatify, Breeze và các thư viện giao diện là thành phần mã nguồn mở; tên package, giấy phép và thông báo bản quyền trong dependency được giữ nguyên. Khi viết báo cáo, phân biệt phần thư viện sử dụng và phần cá nhân phát triển/tùy chỉnh.

Đã gỡ Git remote của kho cũ. Lịch sử commit được giữ để truy vết và phục hồi thay đổi; nó không hiển thị trên giao diện demo. Khi có kho GitHub cá nhân, thêm remote bằng `git remote add origin <URL-kho-cua-ban>`.
