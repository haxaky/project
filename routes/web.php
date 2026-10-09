<?php

use App\Http\Controllers\ApiDocumentationController;
use App\Http\Controllers\Chat\GroupController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StoryController;
use App\Http\Controllers\StoryMusicUploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/docs', [ApiDocumentationController::class, 'index'])->name('docs.index');
Route::get('/docs/openapi.json', [ApiDocumentationController::class, 'specification'])->name('docs.openapi');
Route::get('/docs/csrf', [ApiDocumentationController::class, 'csrf'])->name('docs.csrf');

Route::post('/'.config('chatify.routes.prefix').'/poll', [\App\Http\Controllers\Chat\MessagesController::class, 'poll'])->middleware('auth')->name('messages.poll');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/groups', [GroupController::class, 'index'])->name('groups.index');
    Route::post('/groups', [GroupController::class, 'store'])->name('groups.store');
    Route::get('/groups/{group}', [GroupController::class, 'show'])->name('groups.show');
    Route::post('/groups/{group}/members', [GroupController::class, 'addMembers'])->name('groups.members');
    Route::delete('/groups/{group}/membership', [GroupController::class, 'leave'])->name('groups.leave');
    Route::get('/groups/{group}/messages', [GroupController::class, 'messages'])->name('groups.messages');
    Route::post('/groups/{group}/messages', [GroupController::class, 'send'])->name('groups.send');
    Route::get('/groups/{group}/messages/{message}/attachment', [GroupController::class, 'attachment'])->name('groups.attachment');
    Route::get('/stories', [StoryController::class, 'index'])->name('stories.index');
    Route::post('/stories', [StoryController::class, 'store'])->name('stories.store');
    Route::post('/stories/music-uploads', [StoryMusicUploadController::class, 'store'])->middleware('throttle:15,1')->name('stories.music-uploads.store');
    Route::post('/stories/music-uploads/{upload}/chunks', [StoryMusicUploadController::class, 'chunk'])->name('stories.music-uploads.chunk');
    Route::post('/stories/music-uploads/{upload}/finish', [StoryMusicUploadController::class, 'finish'])->middleware('throttle:15,1')->name('stories.music-uploads.finish');
    Route::delete('/stories/music-uploads/{upload}', [StoryMusicUploadController::class, 'destroy'])->name('stories.music-uploads.cancel');
    Route::get('/stories/{story}/media', [StoryController::class, 'media'])->name('stories.media');
    Route::get('/stories/{story}/music', [StoryController::class, 'music'])->name('stories.music');
    Route::post('/stories/{story}/view', [StoryController::class, 'view'])->name('stories.view');
    Route::get('/stories/{story}/viewers', [StoryController::class, 'viewers'])->name('stories.viewers');
    Route::delete('/stories/{story}', [StoryController::class, 'destroy'])->name('stories.destroy');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
