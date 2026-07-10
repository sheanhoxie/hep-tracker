<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReminderSettingsController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'time' => ['required', 'date_format:H:i'],
        ]);

        $request->user()->reminders()->updateOrCreate(
            ['kind' => 'daily_nudge'],
            ['schedule' => $data['time'], 'enabled' => $data['enabled']],
        );

        return back();
    }
}
