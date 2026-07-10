<?php

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

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
