<?php

namespace App\Http\Controllers;

use App\Models\WorkoutSession;
use App\Models\WorkoutSessionItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SessionItemController extends Controller
{
    public function update(Request $request, WorkoutSession $workoutSession, WorkoutSessionItem $item): RedirectResponse
    {
        abort_unless($workoutSession->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'active', 'done', 'skipped'])],
            'skip_reason' => ['nullable', 'string'],
        ]);

        $item->update([
            'status' => $data['status'],
            'skip_reason' => $data['status'] === 'skipped' ? ($data['skip_reason'] ?? null) : null,
        ]);

        return back();
    }
}
