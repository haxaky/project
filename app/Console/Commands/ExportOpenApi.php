<?php

namespace App\Console\Commands;

use App\Support\OpenApi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportOpenApi extends Command
{
    protected $signature = 'swagger:export {path=docs/openapi.json : Đường dẫn tệp trong dự án}';

    protected $description = 'Xuất tài liệu OpenAPI theo cấu hình hiện tại để nhập vào Swagger/Postman';

    public function handle(OpenApi $openApi): int
    {
        $path = base_path($this->argument('path'));
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($openApi->document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        $this->info('Đã xuất OpenAPI: '.$path);

        return self::SUCCESS;
    }
}
