<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ApiDocumentationController;
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
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
