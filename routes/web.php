<?php

use App\Http\Controllers\Admin\ExerciseController as AdminExerciseController;
use App\Http\Controllers\Admin\ExerciseMediaController as AdminExerciseMediaController;
use App\Http\Controllers\Admin\ProgramController as AdminProgramController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\ObservationController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReminderSettingsController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SessionItemController;
use App\Http\Controllers\SessionItemOrderController;
use App\Http\Controllers\SetEventController;
use App\Http\Controllers\TodayController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('today')
        : Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('today', TodayController::class)->name('today');
    Route::get('library', LibraryController::class)->name('library');
    Route::get('history', HistoryController::class)->name('history');

    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store'])->name('push-subscriptions.store');
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');
    Route::put('reminder-settings', ReminderSettingsController::class)->name('reminder-settings.update');

    Route::post('sessions', [SessionController::class, 'store'])->name('sessions.store');
    Route::get('sessions/{workoutSession}', [SessionController::class, 'show'])->name('sessions.show');
    Route::patch('sessions/{workoutSession}', [SessionController::class, 'update'])->name('sessions.update');
    Route::put('sessions/{workoutSession}/items-order', SessionItemOrderController::class)->name('sessions.items-order');
    Route::post('sessions/{workoutSession}/observations', [ObservationController::class, 'store'])->name('sessions.observations.store');

    Route::scopeBindings()->group(function () {
        Route::patch('sessions/{workoutSession}/items/{item}', [SessionItemController::class, 'update'])->name('sessions.items.update');
        Route::post('sessions/{workoutSession}/items/{item}/set-events', [SetEventController::class, 'store'])->name('sessions.set-events.store');
    });
});

// Content management: manager-only (CONTENT-ADMIN.md W1). Patients get 403.
Route::middleware(['auth', 'verified', 'can:manage-content'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('exercises', [AdminExerciseController::class, 'index'])->name('exercises.index');
    Route::get('exercises/create', [AdminExerciseController::class, 'create'])->name('exercises.create');
    Route::post('exercises', [AdminExerciseController::class, 'store'])->name('exercises.store');
    Route::get('exercises/{exercise:slug}/edit', [AdminExerciseController::class, 'edit'])->name('exercises.edit');
    Route::patch('exercises/{exercise:slug}', [AdminExerciseController::class, 'update'])->name('exercises.update');
    Route::delete('exercises/{exercise:slug}', [AdminExerciseController::class, 'destroy'])->name('exercises.destroy');

    Route::post('exercises/{exercise:slug}/media', [AdminExerciseMediaController::class, 'store'])->name('exercises.media.store');
    Route::scopeBindings()->group(function () {
        Route::delete('exercises/{exercise:slug}/media/{media}', [AdminExerciseMediaController::class, 'destroy'])->name('exercises.media.destroy');
    });

    Route::get('program', [AdminProgramController::class, 'edit'])->name('program.edit');
    Route::put('program', [AdminProgramController::class, 'update'])->name('program.update');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
