<?php

namespace Tests\Feature;

use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoryTest extends TestCase
{
    use RefreshDatabase;

    private function story(User $owner, array $attributes = []): Story
    {
        return Story::create(array_merge([
            'user_id' => $owner->id,
            'body' => 'Một ngày thật đẹp',
            'background' => 'indigo',
            'expires_at' => now()->addDay(),
        ], $attributes));
    }

    public function test_guests_cannot_read_or_create_stories(): void
    {
        $story = $this->story(User::factory()->create());
        $this->get('/stories')->assertRedirect('/login');
        $this->postJson('/stories', ['body' => 'Xin chào', 'background' => 'indigo'])->assertUnauthorized();
        $this->postJson('/stories/'.$story->id.'/view')->assertUnauthorized();
        $this->get('/stories/'.$story->id.'/media')->assertRedirect('/login');
    }

    public function test_create_text_story_expires_in_exactly_24_hours(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/stories', ['body' => 'Hôm nay vui quá', 'background' => 'rose'])->assertRedirect('/messages');
        $story = Story::firstOrFail();
        $this->assertSame($owner->id, $story->user_id);
        $this->assertSame('rose', $story->background);
        $this->assertSame(now()->addDay()->getTimestamp(), $story->expires_at->getTimestamp());
        $this->get('/messages')->assertOk()->assertSee('storyFeed')
            ->assertViewHas('messengerSocial', fn ($data) => $data['stories']->first()['body'] === 'Hôm nay vui quá');
        $this->get('/stories')->assertRedirect('/messages?story=create');
    }

    public function test_image_and_video_uploads_are_private_and_available_to_logged_in_users(): void
    {
        Storage::fake('local');
        [$owner, $viewer] = User::factory()->count(2)->create()->all();
        $this->actingAs($owner)->post('/stories', ['background' => 'indigo', 'media' => UploadedFile::fake()->image('story.png')])->assertRedirect('/messages');
        $image = Story::firstOrFail();
        Storage::disk('local')->assertExists($image->media_path);
        $this->actingAs($viewer)->get('/stories/'.$image->id.'/media')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($owner)->post('/stories', ['background' => 'slate', 'media' => UploadedFile::fake()->create('story.mp4', 100, 'video/mp4')])->assertRedirect('/messages');
        $video = Story::latest('id')->first();
        $this->get('/stories/'.$video->id.'/media')->assertOk()->assertHeader('Content-Type', 'video/mp4');
    }

    public function test_story_validation_rejects_empty_content_invalid_background_and_files(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create())->postJson('/stories', ['background' => 'indigo'])->assertUnprocessable();
        $this->postJson('/stories', ['body' => '   ', 'background' => 'indigo'])->assertUnprocessable();
        $this->postJson('/stories', ['body' => str_repeat('a', 1001), 'background' => 'indigo'])->assertUnprocessable();
        $this->postJson('/stories', ['body' => 'Xin chào', 'background' => 'url(javascript:alert(1))'])->assertUnprocessable();
        $this->postJson('/stories', ['background' => 'rose', 'media' => UploadedFile::fake()->create('unsafe.svg', 1, 'image/svg+xml')])->assertUnprocessable();
        $this->postJson('/stories', ['background' => 'rose', 'media' => UploadedFile::fake()->create('big.mp4', 20481, 'video/mp4')])->assertUnprocessable();
        $this->assertDatabaseCount('stories', 0);
    }

    public function test_story_views_are_unique_owner_excluded_and_viewers_private(): void
    {
        [$owner, $viewer, $other] = User::factory()->count(3)->create()->all();
        $story = $this->story($owner);
        $url = '/stories/'.$story->id;
        $this->actingAs($owner)->postJson($url.'/view')->assertOk();
        $this->assertDatabaseCount('story_views', 0);
        $this->actingAs($viewer)->postJson($url.'/view')->assertOk()->assertJsonPath('viewed', true);
        $this->postJson($url.'/view')->assertOk();
        $this->assertDatabaseCount('story_views', 1);
        $this->getJson($url.'/viewers')->assertForbidden();
        $this->actingAs($other)->getJson($url.'/viewers')->assertForbidden();
        $this->actingAs($owner)->getJson($url.'/viewers')->assertOk()->assertJsonCount(1, 'viewers')->assertJsonPath('viewers.0.name', $viewer->name);
        $this->actingAs($viewer)->getJson('/stories')->assertOk()->assertJsonPath('stories.0.viewed', true)->assertJsonPath('stories.0.views_count', null);
    }

    public function test_only_owner_can_delete_story_and_deletion_cleans_media(): void
    {
        Storage::fake('local');
        [$owner, $viewer] = User::factory()->count(2)->create()->all();
        $this->actingAs($owner)->post('/stories', ['background' => 'indigo', 'media' => UploadedFile::fake()->image('photo.png')]);
        $story = Story::firstOrFail();
        $this->actingAs($viewer)->deleteJson('/stories/'.$story->id)->assertForbidden();
        Storage::disk('local')->assertExists($story->media_path);
        $this->actingAs($owner)->delete('/stories/'.$story->id)->assertRedirect('/messages');
        $this->assertDatabaseCount('stories', 0);
        Storage::disk('local')->assertMissing($story->media_path);
    }

    public function test_expired_stories_are_hidden_and_media_and_view_endpoints_stop_working(): void
    {
        Storage::fake('local');
        $this->freezeTime();
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/stories', ['background' => 'indigo', 'body' => 'Tin đã hết hạn', 'media' => UploadedFile::fake()->image('expired.jpg')]);
        $story = Story::firstOrFail();
        $this->travel(24)->hours();
        $this->story($owner, ['body' => 'Tin còn hiệu lực']);
        $this->getJson('/stories')->assertOk()->assertJsonCount(1, 'stories')->assertJsonPath('stories.0.body', 'Tin còn hiệu lực');
        $this->get('/stories/'.$story->id.'/media')->assertNotFound();
        $this->postJson('/stories/'.$story->id.'/view')->assertNotFound();
        $this->getJson('/stories/'.$story->id.'/viewers')->assertNotFound();
        Storage::disk('local')->assertExists($story->media_path);
        $this->artisan('stories:prune')->assertSuccessful();
        Storage::disk('local')->assertMissing($story->media_path);
        $this->assertDatabaseCount('stories', 1);
    }

    public function test_story_content_is_encoded_safely_for_the_browser(): void
    {
        $owner = User::factory()->create(['name' => '<img src=x onerror=alert(1)>']);
        $this->story($owner, ['body' => '</script><script>alert(1)</script>']);
        $response = $this->actingAs($owner)->get('/messages')->assertOk();
        $response->assertDontSee('</script><script>alert(1)</script>', false);
        $response->assertSee('\\u003C', false);
    }
}
