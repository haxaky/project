<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('chat_group_user', function (Blueprint $table) {
            $table->foreignId('chat_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['chat_group_id', 'user_id']);
        });

        Schema::create('group_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime')->nullable();
            $table->timestamps();
            $table->index(['chat_group_id', 'id']);
        });

        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_mime')->nullable();
            $table->string('background', 20)->default('indigo');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('story_views', function (Blueprint $table) {
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('viewed_at');
            $table->primary(['story_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_views');
        Schema::dropIfExists('stories');
        Schema::dropIfExists('group_messages');
        Schema::dropIfExists('chat_group_user');
        Schema::dropIfExists('chat_groups');
    }
};
