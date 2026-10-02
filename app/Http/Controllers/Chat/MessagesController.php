<?php

namespace App\Http\Controllers\Chat;

use Chatify\Facades\ChatifyMessenger as Chatify;
use Illuminate\Http\Request;

class MessagesController extends \Chatify\Http\Controllers\MessagesController
{
    private function translateEmptyState($response, string $field)
    {
        $data = $response->getData(true);
        $labels = [
            'Say \'hi\' and start messaging' => 'Gửi lời chào để bắt đầu cuộc trò chuyện.',
            'Your contact list is empty' => 'Chưa có cuộc trò chuyện. Tìm người dùng hoặc mở Ghi chú của tôi.',
            'Nothing to show.' => 'Không tìm thấy người dùng.',
            'Nothing shared yet' => 'Chưa có ảnh được chia sẻ.',
        ];
        foreach ($labels as $original => $translated) {
            if (str_contains($data[$field] ?? '', '<span>'.$original.'</span>')) {
                $data[$field] = str_replace('<span>'.$original.'</span>', '<span>'.$translated.'</span>', $data[$field]);
            }
        }

        return $response->setData($data);
    }

    public function fetch(Request $request)
    {
        return $this->translateEmptyState(parent::fetch($request), 'messages');
    }

    public function getContacts(Request $request)
    {
        return $this->translateEmptyState(parent::getContacts($request), 'contacts');
    }

    public function search(Request $request)
    {
        return $this->translateEmptyState(parent::search($request), 'records');
    }

    public function sharedPhotos(Request $request)
    {
        return $this->translateEmptyState(parent::sharedPhotos($request), 'shared');
    }

    public function send(Request $request)
    {
        $request->validate([
            'id' => ['required', 'integer', 'exists:users,id'],
            'message' => ['nullable', 'string', 'max:5000', 'required_without:file'],
            'file' => ['nullable', 'file', 'max:'.(config('chatify.attachments.max_upload_size') * 1024)],
        ]);

        return parent::send($request);
    }

    public function poll(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'integer', 'exists:users,id'],
            'limit' => ['required', 'integer', 'min:30', 'max:10000'],
        ]);
        $query = Chatify::fetchMessagesQuery($data['id']);
        $total = (clone $query)->count();
        $messages = $query->latest()->orderByDesc('id')->take($data['limit'])->get()->reverse();

        return response()->json([
            'total' => $total,
            'signature' => hash('sha256', $messages->toJson()),
            'messages' => $messages->map(fn ($message) => Chatify::messageCard(Chatify::parseMessage($message)))->implode('')
                ?: '<p class="message-hint center-el"><span>Gửi lời chào để bắt đầu cuộc trò chuyện.</span></p>',
        ]);
    }
}
