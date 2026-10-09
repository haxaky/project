<?php

namespace Tests\Feature;

use App\Models\Story;
use App\Models\User;
use App\Services\StoryMusicUploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StoryMusicUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function createUpload(int $size = 10, int $start = 0, int $duration = 15): array
    {
        return $this->postJson('/stories/music-uploads', ['name' => 'song.mp3', 'size' => $size, 'start' => $start, 'duration' => $duration])->assertCreated()->json();
    }

    private function sourceAudio(int $seconds = 75): string
    {
        if (! (new ExecutableFinder)->find('ffmpeg') || ! (new ExecutableFinder)->find('ffprobe')) {
            $this->markTestSkipped('FFmpeg and FFprobe are needed for real music-cutting tests.');
        }
        $path = Storage::disk('local')->path('source.mp3');
        (new Process(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=880:sample_rate=44100', '-t', (string) $seconds, '-c:a', 'libmp3lame', '-b:a', '320k', $path]))->setTimeout(30)->mustRun();

        return file_get_contents($path);
    }

    private function uploadAudio(string $bytes, int $start = 0, int $duration = 15): array
    {
        $upload = $this->createUpload(strlen($bytes), $start, $duration);
        for ($offset = 0; $offset < strlen($bytes); $offset += $upload['chunk_size']) {
            $this->postJson($upload['upload_url'], ['offset' => $offset, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', substr($bytes, $offset, $upload['chunk_size']))])
                ->assertOk()->assertJsonPath('received', min(strlen($bytes), $offset + $upload['chunk_size']));
        }

        return $upload;
    }

    private function assertClipLength(string $path, float $seconds): void
    {
        $probe = (new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $path]))->mustRun();
        $this->assertEqualsWithDelta($seconds, (float) trim($probe->getOutput()), 0.15);
    }

    public function test_mp3_larger_than_php_limit_is_uploaded_in_chunks_and_physically_cut(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bytes = $this->sourceAudio();
        $this->assertGreaterThan(2 * 1024 * 1024, strlen($bytes));
        $upload = $this->uploadAudio($bytes, 10, 12);
        $clip = $this->postJson($upload['finish_url'])->assertOk()->assertJsonPath('music_duration', 12)->json();
        $this->postJson($upload['finish_url'])->assertOk()->assertJsonPath('music_token', $clip['music_token']);
        $directory = 'story-music-uploads/'.$owner->id.'/'.$clip['music_token'];
        Storage::disk('local')->assertMissing($directory.'/source');
        $this->assertClipLength(Storage::disk('local')->path($directory.'/clip.mp3'), 12);
        $this->postJson('/stories', ['background' => 'rose', 'music_token' => $clip['music_token'], 'music_start' => 999, 'music_duration' => 30])
            ->assertCreated()->assertJsonPath('stories.0.music_start', 0)->assertJsonPath('stories.0.music_duration', 12)->assertJsonPath('stories.0.music_title', 'song.mp3');
        $story = Story::firstOrFail();
        $this->assertLessThan(300000, Storage::disk('local')->size($story->music_path));
        $this->assertClipLength(Storage::disk('local')->path($story->music_path), 12);
        $this->assertSame([], Storage::disk('local')->allFiles($directory));
        $this->get('/stories/'.$story->id.'/music')->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->postJson('/stories', ['background' => 'rose', 'music_token' => $clip['music_token']])->assertNotFound();
        $this->assertDatabaseCount('stories', 1);
    }

    public function test_direct_small_music_upload_is_also_really_cut_with_default_15_seconds(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/stories', ['background' => 'indigo', 'music' => UploadedFile::fake()->createWithContent('song.mp3', $this->sourceAudio(20))])
            ->assertCreated()->assertJsonPath('stories.0.music_start', 0)->assertJsonPath('stories.0.music_duration', 15);
        $this->assertClipLength(Storage::disk('local')->path(Story::firstOrFail()->music_path), 15);
        $this->assertSame([], Storage::disk('local')->allFiles('story-music-staging'));
    }

    public function test_selected_offset_is_removed_from_the_audio_file_not_just_player_settings(): void
    {
        $this->actingAs(User::factory()->create());
        $this->sourceAudio(1);
        $source = Storage::disk('local')->path('silence-then-music.wav');
        (new Process(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'aevalsrc=if(lt(t\,5)\,0\,0.15*sin(2*PI*880*t)):s=44100:d=20', $source]))->mustRun();
        $this->postJson('/stories', ['background' => 'indigo', 'music' => UploadedFile::fake()->createWithContent('song.wav', file_get_contents($source)), 'music_start' => 6, 'music_duration' => 5])->assertCreated();
        $story = Story::firstOrFail();
        $this->assertSame(0, $story->music_start);
        $this->assertClipLength(Storage::disk('local')->path($story->music_path), 5);
        $decode = (new Process(['ffmpeg', '-v', 'error', '-i', Storage::disk('local')->path($story->music_path), '-t', '1', '-f', 's16le', '-ac', '1', '-ar', '8000', 'pipe:1']))->mustRun();
        $samples = unpack('v*', $decode->getOutput());
        $amplitude = array_sum(array_map(fn ($sample) => abs($sample > 32767 ? $sample - 65536 : $sample), $samples)) / count($samples);
        $this->assertGreaterThan(1000, $amplitude);
    }

    public function test_m4a_ogg_and_wav_are_converted_to_real_mp3_clips(): void
    {
        $this->actingAs(User::factory()->create());
        $this->sourceAudio(20);
        foreach (['m4a', 'ogg', 'wav'] as $extension) {
            $source = Storage::disk('local')->path('song.'.$extension);
            (new Process(['ffmpeg', '-v', 'error', '-y', '-i', Storage::disk('local')->path('source.mp3'), $source]))->mustRun();
            $upload = $this->uploadAudio(file_get_contents($source), 2, 5);
            $clip = $this->postJson($upload['finish_url'])->assertOk()->json();
            $this->postJson('/stories', ['background' => 'indigo', 'music_token' => $clip['music_token']])->assertCreated();
            $story = Story::latest('id')->firstOrFail();
            $this->assertSame('audio/mpeg', $story->music_mime);
            $this->assertClipLength(Storage::disk('local')->path($story->music_path), 5);
        }
    }

    public function test_start_beyond_music_length_and_corrupt_audio_are_rejected_without_story(): void
    {
        $this->actingAs(User::factory()->create());
        $upload = $this->uploadAudio($this->sourceAudio(10), 11);
        $this->postJson($upload['finish_url'])->assertUnprocessable()->assertJsonValidationErrors('music_start');
        $this->deleteJson($upload['cancel_url'])->assertOk();
        $bad = $this->uploadAudio('not really an mp3');
        $this->postJson($bad['finish_url'])->assertUnprocessable()->assertJsonValidationErrors('music');
        $this->assertDatabaseCount('stories', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('story-music'));
    }

    public function test_clip_at_end_of_song_uses_actual_remaining_duration(): void
    {
        $this->actingAs(User::factory()->create());
        $upload = $this->uploadAudio($this->sourceAudio(10), 8, 15);
        $clip = $this->postJson($upload['finish_url'])->assertOk()->json();
        $this->assertLessThanOrEqual(3, $clip['music_duration']);
        $this->postJson('/stories', ['background' => 'rose', 'music_token' => $clip['music_token']])->assertCreated();
        $this->assertClipLength(Storage::disk('local')->path(Story::firstOrFail()->music_path), 2);
    }

    public function test_uploads_are_owned_and_cannot_be_used_by_other_accounts(): void
    {
        [$owner, $other] = User::factory()->count(2)->create()->all();
        $this->actingAs($owner);
        $upload = $this->createUpload();
        $token = basename(parse_url($upload['cancel_url'], PHP_URL_PATH));
        $this->actingAs($other)->postJson($upload['upload_url'], ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', str_repeat('a', 10))])->assertNotFound();
        $this->postJson($upload['finish_url'])->assertNotFound();
        $this->deleteJson($upload['cancel_url'])->assertNotFound();
        $this->postJson('/stories', ['background' => 'rose', 'music_token' => $token])->assertNotFound();
        $this->actingAs($owner)->deleteJson($upload['cancel_url'])->assertOk();
        $this->assertDatabaseCount('stories', 0);
    }

    public function test_partial_wrong_order_and_oversized_chunks_cannot_be_finalized(): void
    {
        $this->actingAs(User::factory()->create());
        $upload = $this->createUpload(StoryMusicUploads::CHUNK_SIZE + 10);
        $this->postJson($upload['finish_url'])->assertUnprocessable();
        $this->postJson($upload['upload_url'], ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'short')])->assertUnprocessable();
        $this->postJson($upload['upload_url'], ['offset' => 1, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', str_repeat('a', StoryMusicUploads::CHUNK_SIZE))])->assertConflict();
        $this->postJson($upload['upload_url'], ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', str_repeat('a', StoryMusicUploads::CHUNK_SIZE + 1))])->assertUnprocessable();
        $this->postJson($upload['upload_url'], ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', str_repeat('a', StoryMusicUploads::CHUNK_SIZE))])->assertOk();
        $this->postJson($upload['upload_url'], ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', str_repeat('a', StoryMusicUploads::CHUNK_SIZE))])->assertConflict();
        $this->postJson('/stories', ['background' => 'rose', 'music_token' => basename(parse_url($upload['cancel_url'], PHP_URL_PATH))])->assertUnprocessable();
        $this->assertDatabaseCount('stories', 0);
    }

    public function test_expired_and_cancelled_uploads_are_cleaned_and_rejected(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $cancelled = $this->createUpload();
        $this->deleteJson($cancelled['cancel_url'])->assertOk();
        $this->postJson($cancelled['finish_url'])->assertNotFound();
        $expired = $this->createUpload();
        $this->travel(61)->minutes();
        $this->postJson($expired['finish_url'])->assertStatus(410);
        $this->artisan('stories:prune')->assertSuccessful();
        $this->assertSame([], Storage::disk('local')->allFiles('story-music-uploads'));
    }

    public function test_upload_validation_and_authentication(): void
    {
        $this->postJson('/stories/music-uploads')->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $base = ['name' => 'song.mp3', 'size' => 10, 'start' => 0, 'duration' => 15];
        foreach ([['size' => 52428801], ['size' => 0], ['name' => 'bad.html'], ['start' => -1], ['duration' => 31], ['duration' => 4]] as $change) {
            $this->postJson('/stories/music-uploads', array_replace($base, $change))->assertUnprocessable();
        }
        $this->postJson('/stories/music-uploads/not-a-token/finish')->assertNotFound();
        $this->createUpload();
        $this->createUpload();
        $this->createUpload();
        $this->postJson('/stories/music-uploads', $base)->assertStatus(429);
    }

    public function test_missing_transcoder_returns_actionable_error(): void
    {
        $this->actingAs(User::factory()->create());
        $bytes = $this->sourceAudio(10);
        config(['services.story_music.ffmpeg' => '/nonexistent/ffmpeg']);
        $upload = $this->uploadAudio($bytes);
        $this->postJson($upload['finish_url'])->assertStatus(503)->assertJsonPath('message', 'Máy chủ chưa có FFmpeg/FFprobe để cắt nhạc.');
        $this->assertDatabaseCount('stories', 0);
    }
}
