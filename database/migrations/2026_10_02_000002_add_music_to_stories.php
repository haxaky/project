<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->string('music_path')->nullable();
            $table->string('music_mime')->nullable();
            $table->string('music_title', 100)->nullable();
            $table->unsignedSmallInteger('music_start')->default(0);
            $table->unsignedTinyInteger('music_duration')->default(15);
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn(['music_path', 'music_mime', 'music_title', 'music_start', 'music_duration']);
        });
    }
};
