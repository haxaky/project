<?php

namespace Tests\Feature;

use App\Models\ChMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PersonalMessengerTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_leaves_personal_accounts_empty(): void
    {
        $this->seed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_chat_requires_authentication(): void
    {
        $this->get('/messages')->assertRedirect('/login');
        $this->postJson('/messages/poll', ['id' => 1, 'limit' => 30])->assertUnauthorized();
        $this->postJson('/messages/api/sendMessage', [])->assertUnauthorized();
    }

    public function test_personal_chat_renders_without_exposing_service_secrets(): void
    {
        config(['chatify.pusher.secret' => 'private-test-secret']);
        $response = $this->actingAs(User::factory()->create())->get('/messages');
        $response->assertOk()->assertSee('Ghi chú của tôi')->assertSee('images/avatar.svg')->assertDontSee('private-test-secret');
    }

    public function test_self_notes_and_messages_work_without_pusher(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        foreach ([$sender, $recipient] as $target) {
            $this->actingAs($sender)->postJson('/messages/sendMessage', [
                'id' => $target->id,
                'message' => 'Tin nhắn cá nhân <script>alert(1)</script>',
                'temporaryMsgId' => 'temp_1',
            ])->assertOk()->assertJsonPath('error.status', 0);
        }
        $this->assertDatabaseCount('ch_messages', 2);
        $this->assertDatabaseHas('ch_messages', ['from_id' => $sender->id, 'to_id' => $recipient->id]);
    }

    public function test_polling_only_returns_the_current_users_conversation(): void
    {
        $owner = User::factory()->create();
        $peer = User::factory()->create();
        $outsider = User::factory()->create();
        $this->actingAs($owner)->postJson('/messages/sendMessage', ['id' => $peer->id, 'message' => 'Visible conversation']);
        $this->actingAs($outsider)->postJson('/messages/sendMessage', ['id' => $peer->id, 'message' => 'Unrelated private conversation']);

        $this->actingAs($owner)->postJson('/messages/poll', ['id' => $peer->id, 'limit' => 30])
            ->assertOk()->assertJsonPath('total', 1)->assertSee('Visible conversation')->assertDontSee('Unrelated private conversation');
    }

    public function test_invalid_recipient_and_empty_messages_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/messages/sendMessage', ['id' => 999, 'message' => 'hello'])
            ->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->postJson('/messages/sendMessage', ['id' => $user->id])
            ->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->assertDatabaseCount('ch_messages', 0);
    }

    public function test_personal_image_attachments_are_stored_locally(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $this->actingAs($user)->post('/messages/sendMessage', [
            'id' => $user->id,
            'file' => UploadedFile::fake()->image('personal-note.png'),
        ])->assertOk()->assertJsonPath('error.status', 0);

        $attachment = json_decode(ChMessage::first()->attachment);
        Storage::disk('public')->assertExists('attachments/'.$attachment->new_name);
        $this->get('/messages/download/'.$attachment->new_name)->assertOk();
    }

    public function test_public_image_urls_use_the_current_site_host(): void
    {
        config(['app.url' => 'http://127.0.0.1:8000']);

        $this->assertSame('/storage/users-avatar/avatar.jpg', Storage::disk('public')->url('users-avatar/avatar.jpg'));
        $this->assertSame('/storage/attachments/photo.jpg', Storage::disk('public')->url('attachments/photo.jpg'));
    }

    public function test_read_receipts_and_deletions_are_reflected_in_polling(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $this->actingAs($sender)->postJson('/messages/sendMessage', ['id' => $recipient->id, 'message' => 'A message']);
        $before = $this->postJson('/messages/poll', ['id' => $recipient->id, 'limit' => 30])->json('signature');
        $this->actingAs($recipient)->postJson('/messages/makeSeen', ['id' => $sender->id])->assertOk();
        $after = $this->actingAs($sender)->postJson('/messages/poll', ['id' => $recipient->id, 'limit' => 30]);
        $this->assertNotSame($before, $after->json('signature'));
        $this->postJson('/messages/deleteMessage', ['id' => ChMessage::first()->id])->assertOk();
        $this->postJson('/messages/poll', ['id' => $recipient->id, 'limit' => 30])->assertJsonPath('total', 0);
    }
}
