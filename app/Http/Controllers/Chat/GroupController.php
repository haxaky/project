<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\ChatGroup;
use App\Models\GroupMessage;
use App\Services\MessengerSocial;
use Chatify\Facades\ChatifyMessenger as Chatify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GroupController extends Controller
{
    public function index(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['groups' => app(MessengerSocial::class)->groups($request->user())])->header('Cache-Control', 'no-store, private');
        }

        return redirect()->route(config('chatify.routes.prefix'));
    }

    public function show(Request $request, ChatGroup $group)
    {
        abort_unless($group->hasMember($request->user()), 403);

        if ($request->expectsJson()) {
            return response()->json(['group' => app(MessengerSocial::class)->group($group)])->header('Cache-Control', 'no-store, private');
        }

        return redirect()->route(config('chatify.routes.prefix'), ['group' => $group->id]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'members' => ['required', 'array', 'min:1', 'max:99'],
            'members.*' => ['required', 'integer', 'distinct', 'exists:users,id', Rule::notIn([$request->user()->id])],
        ]);
        $group = DB::transaction(function () use ($request, $data) {
            $group = ChatGroup::create(['name' => $data['name'], 'owner_id' => $request->user()->id]);
            $group->members()->attach(array_merge([$request->user()->id], $data['members']));

            return $group;
        });

        if ($request->expectsJson()) {
            return response()->json(['group' => app(MessengerSocial::class)->group($group)], 201);
        }

        return redirect()->route(config('chatify.routes.prefix'), ['group' => $group->id])->with('status', 'Đã tạo nhóm trò chuyện.');
    }

    public function addMembers(Request $request, ChatGroup $group)
    {
        abort_unless($group->hasMember($request->user()) && $group->owner_id === $request->user()->id, 403);
        $data = $request->validate([
            'members' => ['required', 'array', 'min:1', 'max:99'],
            'members.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ]);
        DB::transaction(function () use ($request, $group, $data) {
            $group = ChatGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            abort_unless($group->hasMember($request->user()) && $group->owner_id === $request->user()->id, 403);
            $memberIds = $group->members()->pluck('users.id')->merge($data['members'])->unique();
            if ($memberIds->count() > 100) {
                throw ValidationException::withMessages(['members' => 'Nhóm tối đa 100 thành viên.']);
            }
            $group->members()->syncWithoutDetaching($data['members']);
            $group->touch();
        });

        if ($request->expectsJson()) {
            return response()->json(['group' => app(MessengerSocial::class)->group($group->fresh())]);
        }

        return back()->with('status', 'Đã thêm thành viên.');
    }

    public function leave(Request $request, ChatGroup $group)
    {
        abort_unless($group->hasMember($request->user()), 403);
        DB::transaction(function () use ($request, $group) {
            $group = ChatGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $group->members()->detach($request->user()->id);
            $nextOwner = $group->members()->orderBy('users.id')->first();
            if (! $nextOwner) {
                foreach ($group->messages()->whereNotNull('attachment_path')->cursor() as $message) {
                    Storage::disk('local')->delete($message->attachment_path);
                }
                $group->delete();
            } elseif ($group->owner_id === $request->user()->id) {
                $group->update(['owner_id' => $nextOwner->id]);
            }
        });

        if ($request->expectsJson()) {
            return response()->json(['left' => true]);
        }

        return redirect()->route(config('chatify.routes.prefix'))->with('status', 'Bạn đã rời nhóm.');
    }

    public function messages(Request $request, ChatGroup $group)
    {
        abort_unless($group->hasMember($request->user()), 403);
        $data = $request->validate([
            'after' => ['nullable', 'integer', 'min:0', 'prohibits:before'],
            'before' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = $group->messages()->with('user:id,name,avatar');
        if (isset($data['after'])) {
            $query->where('id', '>', $data['after'])->orderBy('id');
        } else {
            if (isset($data['before'])) {
                $query->where('id', '<', $data['before']);
            }
            $query->orderByDesc('id');
        }
        $messages = $query->limit(50)->get()->sortBy('id')->values();

        return response()->json([
            'messages' => $messages->map(fn ($message) => $this->messageData($message)),
            'has_more' => $messages->count() === 50,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function send(Request $request, ChatGroup $group)
    {
        abort_unless($group->hasMember($request->user()), 403);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000', 'required_without:file'],
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp,pdf,txt,zip,doc,docx,xls,xlsx,ppt,pptx', 'max:10240'],
        ]);
        $file = $request->file('file');
        $path = $file?->store('group-attachments', 'local');
        abort_if($file && ! $path, 500, 'Không lưu được tệp. Vui lòng thử lại.');
        try {
            $message = DB::transaction(function () use ($request, $group, $data, $file, $path) {
                $group = ChatGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
                abort_unless($group->hasMember($request->user()), 403);
                $message = $group->messages()->create([
                    'user_id' => $request->user()->id,
                    'body' => $data['body'] ?? null,
                    'attachment_path' => $path,
                    'attachment_name' => $file?->getClientOriginalName(),
                    'attachment_mime' => $file?->getMimeType(),
                ]);
                $group->touch();

                return $message;
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return response()->json(['message' => $this->messageData($message->load('user:id,name,avatar'))], 201);
    }

    public function attachment(Request $request, ChatGroup $group, GroupMessage $message)
    {
        abort_unless($group->hasMember($request->user()), 403);
        abort_unless($message->chat_group_id === $group->id && $message->attachment_path, 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($message->attachment_path), 404);
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private'];
        if (str_starts_with($message->attachment_mime, 'image/')) {
            return response()->file($disk->path($message->attachment_path), $headers);
        }

        return $disk->download($message->attachment_path, $message->attachment_name, $headers);
    }

    private function messageData(GroupMessage $message): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'user_id' => $message->user_id,
            'user_name' => $message->user?->name ?? 'Tài khoản đã xóa',
            'avatar' => Chatify::getUserAvatarUrl($message->user?->avatar),
            'created_at' => $message->created_at->toIso8601String(),
            'attachment_url' => $message->attachment_path ? route('groups.attachment', [$message->chat_group_id, $message]) : null,
            'attachment_name' => $message->attachment_name,
            'attachment_mime' => $message->attachment_mime,
        ];
    }
}
