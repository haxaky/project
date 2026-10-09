<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoryMusicUploads
{
    public const CHUNK_SIZE = 524288;

    public const MAX_SIZE = 52428800;

    public function create(int $userId, array $data): string
    {
        $this->prune();
        $disk = Storage::disk('local');
        abort_if(count($disk->directories('story-music-uploads/'.$userId)) >= 3, 429, 'Bạn đang có nhiều bài nhạc tải dở. Hãy chờ hoặc hủy lần tải trước.');
        $token = (string) Str::uuid();
        $directory = $this->directory($userId, $token);
        $disk->makeDirectory($directory);
        $disk->put($directory.'/lock', '');
        $disk->put($directory.'/source', '');
        $disk->put($directory.'/metadata.json', json_encode($data + ['received' => 0, 'expires_at' => now()->addHour()->timestamp], JSON_THROW_ON_ERROR));

        return $token;
    }

    public function append(int $userId, string $token, int $offset, UploadedFile $chunk): array
    {
        return $this->locked($userId, $token, function (array &$metadata, string $directory) use ($offset, $chunk) {
            abort_if(isset($metadata['clip']), 409, 'Bài nhạc này đã được cắt.');
            abort_if($offset !== $metadata['received'], 409, 'Phần nhạc tải lên không đúng thứ tự. Vui lòng thử lại.');
            $size = $chunk->getSize();
            $expected = min(self::CHUNK_SIZE, $metadata['size'] - $metadata['received']);
            abort_if($size < 1 || $size !== $expected, 422, 'Kích thước phần nhạc tải lên không hợp lệ.');
            $source = Storage::disk('local')->path($directory.'/source');
            clearstatcache(true, $source);
            abort_if(filesize($source) !== $offset, 409, 'Tệp tải dở không hợp lệ. Vui lòng tải lại.');
            $written = file_put_contents($source, file_get_contents($chunk->getRealPath()), FILE_APPEND);
            abort_unless($written === $size, 500, 'Không lưu được nhạc. Vui lòng thử lại.');
            $metadata['received'] += $size;

            return ['received' => $metadata['received'], 'size' => $metadata['size']];
        });
    }

    public function finish(int $userId, string $token): array
    {
        return $this->locked($userId, $token, function (array &$metadata, string $directory) {
            abort_unless($metadata['received'] === $metadata['size'], 422, 'Bài nhạc chưa tải lên đủ. Vui lòng thử lại.');
            if (! isset($metadata['clip'])) {
                $disk = Storage::disk('local');
                $metadata['clip'] = app(StoryMusicClipper::class)->cut($disk->path($directory.'/source'), $directory.'/clip.mp3', $metadata['start'], $metadata['duration']);
                $disk->delete($directory.'/source');
            }

            return ['music_token' => basename($directory), 'music_duration' => $metadata['clip']['duration']];
        });
    }

    public function consume(int $userId, string $token, callable $callback): mixed
    {
        return $this->locked($userId, $token, function (array &$metadata, string $directory) use ($callback) {
            abort_unless(isset($metadata['clip']), 422, 'Bài nhạc chưa được cắt xong.');
            $result = $callback($metadata['clip'] + ['name' => $metadata['name']]);
            Storage::disk('local')->deleteDirectory($directory);

            return $result;
        });
    }

    public function cancel(int $userId, string $token): void
    {
        $this->locked($userId, $token, function (array &$metadata, string $directory) {
            Storage::disk('local')->deleteDirectory($directory);
        });
    }

    public function prune(): int
    {
        $disk = Storage::disk('local');
        $count = 0;
        foreach ($disk->directories('story-music-uploads') as $ownerDirectory) {
            foreach ($disk->directories($ownerDirectory) as $directory) {
                $lock = @fopen($disk->path($directory.'/lock'), 'r+');
                if (! $lock) {
                    continue;
                }
                try {
                    if (! flock($lock, LOCK_EX | LOCK_NB)) {
                        continue;
                    }
                    $metadata = json_decode($disk->get($directory.'/metadata.json') ?? '{}', true);
                    if (($metadata['expires_at'] ?? 0) <= now()->timestamp) {
                        $disk->deleteDirectory($directory);
                        $count++;
                    }
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
        }

        return $count;
    }

    private function directory(int $userId, string $token): string
    {
        abort_unless(Str::isUuid($token), 404);

        return 'story-music-uploads/'.$userId.'/'.$token;
    }

    private function locked(int $userId, string $token, callable $callback): mixed
    {
        $disk = Storage::disk('local');
        $directory = $this->directory($userId, $token);
        $lock = @fopen($disk->path($directory.'/lock'), 'r+');
        abort_unless($lock, 404, 'Không tìm thấy lần tải nhạc này. Vui lòng tải lại.');
        try {
            abort_unless(flock($lock, LOCK_EX), 503, 'Nhạc đang được xử lý. Vui lòng thử lại.');
            $metadata = json_decode($disk->get($directory.'/metadata.json') ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            abort_unless(($metadata['expires_at'] ?? 0) > now()->timestamp, 410, 'Lần tải nhạc đã hết hạn. Vui lòng tải lại.');
            $result = $callback($metadata, $directory);
            if ($disk->exists($directory.'/metadata.json')) {
                $disk->put($directory.'/metadata.json', json_encode($metadata, JSON_THROW_ON_ERROR));
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
