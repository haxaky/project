<?php

namespace App\Console\Commands;

use App\Models\Story;
use App\Services\StoryMusicUploads;
use Illuminate\Console\Command;

class PruneStories extends Command
{
    protected $signature = 'stories:prune';

    protected $description = 'Xóa story hết hạn, tệp ảnh/video/nhạc và nhạc tải dở';

    public function handle(): int
    {
        $count = 0;
        Story::where('expires_at', '<=', now())->chunkById(100, function ($stories) use (&$count) {
            foreach ($stories as $story) {
                $story->delete();
                $count++;
            }
        });
        $this->info('Đã xóa '.$count.' story hết hạn.');
        $this->info('Đã dọn '.app(StoryMusicUploads::class)->prune().' lần tải nhạc hết hạn.');

        return self::SUCCESS;
    }
}
