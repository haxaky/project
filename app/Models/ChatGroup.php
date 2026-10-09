<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatGroup extends Model
{
    protected $fillable = ['name', 'owner_id'];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(GroupMessage::class);
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->where('users.id', $user->id)->exists();
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(GroupMessage::class)->latestOfMany();
    }
}
