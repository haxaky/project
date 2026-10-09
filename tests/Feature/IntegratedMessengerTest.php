<?php

namespace Tests\Feature;

use App\Models\Story;
use App\Models\User;
use App\Services\StoryMusicClipper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IntegratedMessengerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(StoryMusicClipper::class, function ($mock) {
            $mock->shouldReceive('cut')->andReturnUsing(function (string $source, string $destination, int $start, int $duration) {
                Storage::disk('local')->put($destination, 'clipped-audio');

                return ['path' => $destination, 'duration' => $duration];
            });
        });
    }

    public function test_groups_and_stories_share_the_existing_messenger_without_new_pages(): void
    {
        [$owner, $member, $outsider] = User::factory()->count(3)->create()->all();
        $group = $this->actingAs($owner)->postJson('/groups', ['name' => 'Nhóm chung', 'members' => [$member->id]])
            ->assertCreated()->json('group');
        $this->getJson('/groups')->assertOk()->assertJsonCount(1, 'groups')->assertJsonPath('groups.0.name', 'Nhóm chung');
        $this->actingAs($member)->get('/messages?group='.$group['id'])->assertOk()->assertSee('message-form');
        $this->actingAs($outsider)->get('/messages?group='.$group['id'])->assertForbidden();
        $this->getJson('/groups')->assertOk()->assertJsonCount(0, 'groups');
        $this->actingAs($owner)->get('/messages')->assertOk()->assertSee('data-create-group')->assertSee('data-new-story')->assertDontSee('messenger-social-links');
        $this->get('/groups')->assertRedirect('/messages');
        $this->get('/stories')->assertRedirect('/messages?story=create');
    }

    public function test_story_feed_updates_include_other_accounts_stories_and_preserve_viewer_privacy(): void
    {
        [$owner, $viewer] = User::factory()->count(2)->create()->all();
        $this->actingAs($viewer)->getJson('/stories')->assertOk()->assertJsonCount(0, 'stories');
        $this->actingAs($owner)->postJson('/stories', ['body' => 'Story mới', 'background' => 'rose'])->assertCreated();
        $this->actingAs($viewer)->getJson('/stories')->assertOk()->assertJsonPath('stories.0.user_id', $owner->id)
            ->assertJsonPath('stories.0.body', 'Story mới')->assertJsonPath('stories.0.viewed', false)->assertJsonPath('stories.0.views_count', null);
        $this->get('/messages')->assertViewHas('messengerSocial', fn ($data) => $data['stories']->first()['user_id'] === $owner->id);
    }

    public function test_music_only_story_has_private_audio_and_custom_clip_settings(): void
    {
        Storage::fake('local');
        [$owner, $viewer] = User::factory()->count(2)->create()->all();
        $this->actingAs($owner)->postJson('/stories', [
            'background' => 'indigo', 'music' => UploadedFile::fake()->create('song.mp3', 100, 'audio/mpeg'),
            'music_title' => 'Bài hát của tôi', 'music_start' => 12, 'music_duration' => 20,
        ])->assertCreated()->assertJsonPath('stories.0.music_title', 'Bài hát của tôi')->assertJsonPath('stories.0.music_start', 0)->assertJsonPath('stories.0.music_duration', 20);
        $story = Story::firstOrFail();
        Storage::disk('local')->assertExists($story->music_path);
        $this->assertStringStartsWith('story-music/', $story->music_path);
        $this->actingAs($viewer)->get('/stories/'.$story->id.'/music')->assertOk()->assertHeader('Content-Type', 'audio/mpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->app['auth']->forgetGuards();
        $this->getJson('/stories/'.$story->id.'/music')->assertUnauthorized();
    }

    public function test_music_can_be_combined_with_an_image_and_both_files_are_deleted(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $this->actingAs($owner)->postJson('/stories', [
            'background' => 'rose', 'body' => 'Ảnh có nhạc', 'media' => UploadedFile::fake()->image('photo.png'),
            'music' => UploadedFile::fake()->create('song.mp3', 100, 'audio/mpeg'),
        ])->assertCreated();
        $story = Story::firstOrFail();
        Storage::disk('local')->assertExists($story->media_path);
        Storage::disk('local')->assertExists($story->music_path);
        $this->deleteJson('/stories/'.$story->id)->assertOk()->assertJsonPath('deleted', true);
        Storage::disk('local')->assertMissing($story->media_path);
        Storage::disk('local')->assertMissing($story->music_path);
    }

    public function test_music_validation_rejects_unsafe_files_large_uploads_and_invalid_ranges(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $base = ['background' => 'indigo', 'body' => 'Tin có nhạc'];
        $this->postJson('/stories', $base + ['music' => UploadedFile::fake()->create('bad.html', 1, 'text/html')])->assertUnprocessable();
        $this->postJson('/stories', $base + ['music' => UploadedFile::fake()->create('large.mp3', 10241, 'audio/mpeg')])->assertUnprocessable();
        $this->postJson('/stories', $base + ['music_start' => -1])->assertUnprocessable();
        $this->postJson('/stories', $base + ['music_duration' => 31])->assertUnprocessable();
        $this->postJson('/stories', $base + ['music_duration' => 4])->assertUnprocessable();
        $this->assertDatabaseCount('stories', 0);
    }

    public function test_music_expires_with_story_and_pruning_removes_audio(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create())->postJson('/stories', [
            'background' => 'indigo', 'music' => UploadedFile::fake()->create('song.mp3', 100, 'audio/mpeg'),
        ])->assertCreated();
        $story = Story::firstOrFail();
        $this->travel(24)->hours();
        $this->get('/stories/'.$story->id.'/music')->assertNotFound();
        $this->getJson('/stories')->assertOk()->assertJsonCount(0, 'stories');
        $this->artisan('stories:prune')->assertSuccessful();
        Storage::disk('local')->assertMissing($story->music_path);
    }
}
