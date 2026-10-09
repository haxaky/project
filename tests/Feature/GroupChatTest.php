<?php

namespace Tests\Feature;

use App\Models\ChatGroup;
use App\Models\GroupMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GroupChatTest extends TestCase
{
    use RefreshDatabase;

    private function group(User $owner, array $members = []): ChatGroup
    {
        $group = ChatGroup::create(['name' => 'Nhóm bạn thân', 'owner_id' => $owner->id]);
        $group->members()->attach(array_merge([$owner->id], $members));

        return $group;
    }

    public function test_group_pages_and_story_links_render(): void
    {
        $owner = User::factory()->create();
        $group = $this->group($owner);
        $this->actingAs($owner)->get('/groups')->assertRedirect('/messages');
        $this->get('/groups/'.$group->id)->assertRedirect('/messages?group='.$group->id);
        $this->get('/messages?group='.$group->id)->assertOk()->assertSee('message-form')->assertSee('messenger-story-strip')
            ->assertViewHas('messengerSocial', fn ($data) => $data['selectedGroup']['name'] === 'Nhóm bạn thân');
        $this->getJson('/groups/'.$group->id)->assertOk()->assertJsonPath('group.name', 'Nhóm bạn thân');
        $this->get('/dashboard')->assertOk()->assertDontSee('Đăng story');
    }

    public function test_guest_cannot_access_groups_or_send_messages(): void
    {
        $group = $this->group(User::factory()->create());
        $this->get('/groups')->assertRedirect('/login');
        $this->postJson('/groups', [])->assertUnauthorized();
        $this->getJson('/groups/'.$group->id.'/messages')->assertUnauthorized();
        $this->postJson('/groups/'.$group->id.'/messages', ['body' => 'Xin chào'])->assertUnauthorized();
    }

    public function test_create_group_includes_creator_and_validates_member_ids(): void
    {
        [$owner, $first, $second] = User::factory()->count(3)->create()->all();
        $this->actingAs($owner)->post('/groups', ['name' => 'Đồ án', 'members' => [$first->id, $second->id]])
            ->assertRedirect('/messages?group=1');
        $group = ChatGroup::firstOrFail();
        $this->assertEqualsCanonicalizing([$owner->id, $first->id, $second->id], $group->members()->pluck('users.id')->all());
        $this->postJson('/groups', ['name' => 'Không đủ người', 'members' => []])->assertUnprocessable();
        $this->postJson('/groups', ['name' => 'Sai người', 'members' => [9999]])->assertUnprocessable();
        $this->postJson('/groups', ['name' => 'Trùng người', 'members' => [$first->id, $first->id]])->assertUnprocessable();
        $this->postJson('/groups', ['name' => 'Tự mời', 'members' => [$owner->id]])->assertUnprocessable();
        $this->assertDatabaseCount('chat_groups', 1);
    }

    public function test_only_members_can_read_send_or_download(): void
    {
        Storage::fake('local');
        [$owner, $member, $outsider] = User::factory()->count(3)->create()->all();
        $group = $this->group($owner, [$member->id]);
        $message = $this->actingAs($member)->postJson('/groups/'.$group->id.'/messages', [
            'body' => 'Xin chào cả nhóm',
            'file' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
        ])->assertCreated()->assertJsonPath('message.user_name', $member->name)->json('message');
        $this->actingAs($owner)->getJson('/groups/'.$group->id.'/messages')->assertOk()->assertJsonPath('messages.0.body', 'Xin chào cả nhóm');
        $this->get($message['attachment_url'])->assertOk();
        $this->actingAs($outsider)->get('/groups/'.$group->id)->assertForbidden();
        $this->getJson('/groups/'.$group->id.'/messages')->assertForbidden();
        $this->postJson('/groups/'.$group->id.'/messages', ['body' => 'Không được gửi'])->assertForbidden();
        $this->get($message['attachment_url'])->assertForbidden();
        $this->assertDatabaseCount('group_messages', 1);
        $stored = GroupMessage::firstOrFail();
        Storage::disk('local')->assertExists($stored->attachment_path);
        $this->assertStringStartsWith('group-attachments/', $stored->attachment_path);
    }

    public function test_messages_require_content_and_reject_unsafe_or_oversized_files(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $group = $this->group($owner);
        $url = '/groups/'.$group->id.'/messages';
        $this->actingAs($owner)->postJson($url, [])->assertUnprocessable();
        $this->postJson($url, ['body' => '   '])->assertUnprocessable();
        $this->postJson($url, ['body' => str_repeat('a', 5001)])->assertUnprocessable();
        $this->postJson($url, ['file' => UploadedFile::fake()->create('script.html', 1, 'text/html')])->assertUnprocessable();
        $this->postJson($url, ['file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')])->assertUnprocessable();
        $this->postJson($url, ['file' => UploadedFile::fake()->image('photo.jpg')])->assertCreated();
        $this->get(route('groups.attachment', [$group, GroupMessage::firstOrFail()]))
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_message_polling_and_history_pagination_do_not_leak_other_groups(): void
    {
        $owner = User::factory()->create();
        $group = $this->group($owner);
        $other = $this->group($owner);
        foreach (range(1, 65) as $number) {
            $group->messages()->create(['user_id' => $owner->id, 'body' => 'Tin '.$number]);
        }
        $other->messages()->create(['user_id' => $owner->id, 'body' => 'Nhóm khác']);
        $url = '/groups/'.$group->id.'/messages';
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonCount(50, 'messages')->assertJsonPath('messages.0.id', 16)->assertJsonPath('has_more', true);
        $this->getJson($url.'?before=16')->assertOk()->assertJsonCount(15, 'messages')->assertJsonPath('messages.0.id', 1)->assertJsonPath('has_more', false);
        $this->getJson($url.'?after=60')->assertOk()->assertJsonCount(5, 'messages')->assertJsonPath('messages.0.id', 61);
        $this->getJson($url.'?after=65')->assertOk()->assertJsonCount(0, 'messages');
        $this->getJson($url.'?after=1&before=16')->assertUnprocessable();
    }

    public function test_only_owner_can_add_members_and_duplicate_add_is_idempotent(): void
    {
        [$owner, $member, $newMember] = User::factory()->count(3)->create()->all();
        $group = $this->group($owner, [$member->id]);
        $url = '/groups/'.$group->id.'/members';
        $this->actingAs($member)->postJson($url, ['members' => [$newMember->id]])->assertForbidden();
        $this->actingAs($newMember)->postJson($url, ['members' => [$newMember->id]])->assertForbidden();
        $this->actingAs($owner)->post($url, ['members' => [$newMember->id]])->assertRedirect();
        $this->post($url, ['members' => [$newMember->id]])->assertRedirect();
        $this->assertSame(3, $group->members()->count());
        $this->actingAs($newMember)->getJson('/groups/'.$group->id)->assertOk();
    }

    public function test_leave_revokes_access_transfers_ownership_and_removes_empty_group_files(): void
    {
        Storage::fake('local');
        [$owner, $member] = User::factory()->count(2)->create()->all();
        $group = $this->group($owner, [$member->id]);
        $this->actingAs($owner)->postJson('/groups/'.$group->id.'/messages', ['file' => UploadedFile::fake()->image('photo.png')])->assertCreated();
        $attachment = GroupMessage::firstOrFail()->attachment_path;
        $this->delete('/groups/'.$group->id.'/membership')->assertRedirect('/messages');
        $this->assertSame($member->id, $group->fresh()->owner_id);
        $this->getJson('/groups/'.$group->id.'/messages')->assertForbidden();
        $this->actingAs($member)->delete('/groups/'.$group->id.'/membership')->assertRedirect('/messages');
        $this->assertDatabaseCount('chat_groups', 0);
        $this->assertDatabaseCount('group_messages', 0);
        Storage::disk('local')->assertMissing($attachment);
    }

    public function test_group_member_limit_is_enforced_without_changing_memberships(): void
    {
        $owner = User::factory()->create();
        $members = User::factory()->count(100)->create();
        $group = $this->group($owner, $members->take(99)->modelKeys());
        $this->actingAs($owner)->postJson('/groups/'.$group->id.'/members', ['members' => [$members->last()->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('members');
        $this->assertSame(100, $group->members()->count());
        $this->assertFalse($group->hasMember($members->last()));
        $this->post('/groups/'.$group->id.'/members', ['members' => [$members->first()->id]])->assertRedirect();
        $this->assertSame(100, $group->members()->count());
        $this->postJson('/groups', ['name' => 'Nhóm quá đông', 'members' => $members->modelKeys()])->assertUnprocessable();
    }

    public function test_attachment_cannot_be_requested_with_a_different_group_id(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $first = $this->group($owner);
        $second = $this->group($owner);
        $response = $this->actingAs($owner)->postJson('/groups/'.$first->id.'/messages', ['file' => UploadedFile::fake()->image('image.jpg')]);
        $this->get('/groups/'.$second->id.'/messages/'.$response->json('message.id').'/attachment')->assertNotFound();
    }
}
