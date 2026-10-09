<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class StoryMusicClipper
{
    public function cut(string $source, string $destination, int $start, int $duration): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        if (! in_array($mime, ['audio/mpeg', 'audio/mp4', 'video/mp4', 'audio/x-m4a', 'application/ogg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/vnd.wave'], true)) {
            throw ValidationException::withMessages(['music' => 'Tệp nhạc không hợp lệ. Hãy chọn MP3, M4A, OGG hoặc WAV.']);
        }

        $finder = new ExecutableFinder;
        $ffmpeg = $finder->find(config('services.story_music.ffmpeg'));
        $ffprobe = $finder->find(config('services.story_music.ffprobe'));
        abort_unless($ffmpeg && $ffprobe, 503, 'Máy chủ chưa có FFmpeg/FFprobe để cắt nhạc.');

        $inputOptions = ['-protocol_whitelist', 'file', '-format_whitelist', 'mp3,mov,ogg,wav'];
        $probe = new Process([$ffprobe, '-v', 'error', ...$inputOptions, '-select_streams', 'a:0', '-show_entries', 'stream=duration:format=duration', '-of', 'json', $source]);
        $probe->setTimeout(15);
        try {
            $probe->mustRun();
            $metadata = json_decode($probe->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $length = (float) ($metadata['streams'][0]['duration'] ?? $metadata['format']['duration'] ?? 0);
            if (empty($metadata['streams']) || ! is_finite($length) || $length <= 0) {
                throw new \RuntimeException('Invalid audio duration');
            }
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['music' => 'Không đọc được nhạc. Tệp có thể bị hỏng hoặc không có âm thanh.']);
        }

        if ($start >= $length || $length - $start < 1) {
            throw ValidationException::withMessages(['music_start' => 'Thời điểm bắt đầu nằm ngoài bài nhạc. Hãy chọn đoạn sớm hơn.']);
        }

        $clipDuration = min($duration, $length - $start);
        $disk = Storage::disk('local');
        $disk->makeDirectory(dirname($destination));
        $process = new Process([
            $ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
            ...$inputOptions, '-ss', (string) $start, '-i', $source,
            '-t', (string) $clipDuration, '-map', '0:a:0', '-vn', '-map_metadata', '-1',
            '-c:a', 'libmp3lame', '-b:a', '160k', '-ar', '44100', '-ac', '2', '-threads', '1',
            '-fs', '1000000', '-f', 'mp3', $disk->path($destination),
        ]);
        $process->setTimeout(60);
        try {
            $process->mustRun();
            if (! $disk->exists($destination) || $disk->size($destination) < 100) {
                throw new \RuntimeException('Empty music clip');
            }
        } catch (\Throwable $exception) {
            $disk->delete($destination);
            throw ValidationException::withMessages(['music' => 'Không cắt được nhạc. Hãy thử tệp nhạc khác.']);
        }

        return ['path' => $destination, 'duration' => (int) ceil($clipDuration)];
    }
}
