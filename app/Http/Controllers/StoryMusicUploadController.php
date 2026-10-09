<?php

namespace App\Http\Controllers;

use App\Services\StoryMusicUploads;
use Illuminate\Http\Request;

class StoryMusicUploadController extends Controller
{
    public function store(Request $request, StoryMusicUploads $uploads)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/\.(mp3|m4a|ogg|wav)$/i'],
            'size' => ['required', 'integer', 'min:1', 'max:'.StoryMusicUploads::MAX_SIZE],
            'start' => ['required', 'integer', 'min:0', 'max:3600'],
            'duration' => ['required', 'integer', 'min:5', 'max:30'],
        ]);
        $token = $uploads->create($request->user()->id, $data);

        return response()->json([
            'upload_url' => route('stories.music-uploads.chunk', $token),
            'finish_url' => route('stories.music-uploads.finish', $token),
            'cancel_url' => route('stories.music-uploads.cancel', $token),
            'chunk_size' => StoryMusicUploads::CHUNK_SIZE,
        ], 201);
    }

    public function chunk(Request $request, string $upload, StoryMusicUploads $uploads)
    {
        $data = $request->validate([
            'offset' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file', 'max:512'],
        ]);

        return response()->json($uploads->append($request->user()->id, $upload, $data['offset'], $request->file('chunk')));
    }

    public function finish(Request $request, string $upload, StoryMusicUploads $uploads)
    {
        return response()->json($uploads->finish($request->user()->id, $upload));
    }

    public function destroy(Request $request, string $upload, StoryMusicUploads $uploads)
    {
        $uploads->cancel($request->user()->id, $upload);

        return response()->json(['deleted' => true]);
    }
}
