<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Story extends Model
{
    protected $fillable = ['user_id', 'body', 'media_path', 'media_mime', 'background', 'expires_at', 'music_path', 'music_mime', 'music_title', 'music_start', 'music_duration'];

    protected $casts = ['expires_at' => 'datetime', 'music_start' => 'integer', 'music_duration' => 'integer'];

    protected static function booted(): void
    {
        static::deleted(function (Story $story) {
            foreach ([$story->media_path, $story->music_path] as $path) {
                if ($path) {
                    Storage::disk('local')->delete($path);
                }
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function viewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'story_views')->withPivot('viewed_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
