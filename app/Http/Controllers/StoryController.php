<?php

namespace App\Http\Controllers;

use App\Models\Story;
use App\Services\MessengerSocial;
use App\Services\StoryMusicClipper;
use App\Services\StoryMusicUploads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoryController extends Controller
{
    public function index(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['stories' => app(MessengerSocial::class)->stories($request->user())])->header('Cache-Control', 'no-store, private');
        }

        return redirect()->route(config('chatify.routes.prefix'), ['story' => 'create']);
    }

    public function store(Request $request, StoryMusicUploads $uploads, StoryMusicClipper $clipper)
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:1000', 'required_without_all:media,music,music_token'],
            'media' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp,mp4,webm', 'max:20480'],
            'background' => ['required', Rule::in(['indigo', 'rose', 'emerald', 'amber', 'slate'])],
            'music' => ['nullable', 'file', 'mimes:mp3,m4a,ogg,wav', 'max:10240', Rule::prohibitedIf($request->filled('music_token'))],
            'music_token' => ['nullable', 'uuid', Rule::prohibitedIf($request->hasFile('music'))],
            'music_title' => ['nullable', 'string', 'max:100'],
            'music_start' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'music_duration' => ['nullable', 'integer', 'min:5', 'max:30'],
        ]);
        $media = $request->file('media');
        $music = $request->file('music');
        $save = function (?array $clip) use ($request, $data, $media) {
            $path = null;
            $musicPath = null;
            $disk = Storage::disk('local');
            try {
                $path = $media?->store('stories', 'local');
                abort_if($media && ! $path, 500, 'Không lưu được ảnh/video. Vui lòng thử lại.');
                if ($clip) {
                    $musicPath = 'story-music/'.Str::uuid().'.mp3';
                    abort_unless($disk->copy($clip['path'], $musicPath), 500, 'Không lưu được nhạc. Vui lòng thử lại.');
                }
                Story::create([
                    'user_id' => $request->user()->id,
                    'body' => $data['body'] ?? null,
                    'background' => $data['background'],
                    'media_path' => $path,
                    'media_mime' => $media?->getMimeType(),
                    'expires_at' => now()->addDay(),
                    'music_path' => $musicPath,
                    'music_mime' => $clip ? 'audio/mpeg' : null,
                    'music_title' => $clip ? ($data['music_title'] ?? mb_substr($clip['name'], 0, 100)) : null,
                    'music_start' => 0,
                    'music_duration' => $clip['duration'] ?? 15,
                ]);
            } catch (\Throwable $exception) {
                $disk->delete(array_filter([$path, $musicPath]));
                throw $exception;
            }
        };

        if (! empty($data['music_token'])) {
            $uploads->consume($request->user()->id, $data['music_token'], $save);
        } elseif ($music) {
            $temporaryPath = 'story-music-staging/'.Str::uuid().'.mp3';
            try {
                $clip = $clipper->cut($music->getRealPath(), $temporaryPath, $data['music_start'] ?? 0, $data['music_duration'] ?? 15);
                $save($clip + ['name' => $music->getClientOriginalName()]);
            } finally {
                Storage::disk('local')->delete($temporaryPath);
            }
        } else {
            $save(null);
        }

        if ($request->expectsJson()) {
            return response()->json(['stories' => app(MessengerSocial::class)->stories($request->user())], 201);
        }

        return redirect()->route(config('chatify.routes.prefix'))->with('status', 'Đã đăng story. Tin tự ẩn sau 24 giờ.');
    }

    public function media(Story $story)
    {
        $this->ensureActive($story);
        $disk = Storage::disk('local');
        abort_unless($story->media_path && $disk->exists($story->media_path), 404);

        return response()->file($disk->path($story->media_path), [
            'Content-Type' => $story->media_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function view(Request $request, Story $story)
    {
        $this->ensureActive($story);
        if ($story->user_id !== $request->user()->id) {
            DB::table('story_views')->insertOrIgnore([
                'story_id' => $story->id,
                'user_id' => $request->user()->id,
                'viewed_at' => now(),
            ]);
        }

        return response()->json(['viewed' => true]);
    }

    public function music(Story $story)
    {
        $this->ensureActive($story);
        $disk = Storage::disk('local');
        abort_unless($story->music_path && $disk->exists($story->music_path), 404);

        return response()->file($disk->path($story->music_path), [
            'Content-Type' => $story->music_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function viewers(Request $request, Story $story)
    {
        abort_unless($story->user_id === $request->user()->id, 403);
        $this->ensureActive($story);

        return response()->json(['viewers' => $story->viewers()->orderByPivot('viewed_at', 'desc')->get(['users.id', 'users.name'])
            ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'viewed_at' => $user->pivot->viewed_at])])
            ->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request, Story $story)
    {
        abort_unless($story->user_id === $request->user()->id, 403);
        $story->delete();

        if ($request->expectsJson()) {
            return response()->json(['deleted' => true]);
        }

        return redirect()->route(config('chatify.routes.prefix'))->with('status', 'Đã xóa story.');
    }

    private function ensureActive(Story $story): void
    {
        abort_if($story->expires_at->lte(now()), 404);
    }
}
