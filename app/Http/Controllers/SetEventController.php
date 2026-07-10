<?php

namespace App\Http\Controllers;

use App\Models\SetEvent;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SetEventController extends Controller
{
    /**
     * Append-only, replayed at-least-once from the player's offline queue:
     * createOrFirst on client_key makes duplicate deliveries a no-op
     * (stack-decisions.md rule 4).
     */
    public function store(Request $request, WorkoutSession $workoutSession, WorkoutSessionItem $item): RedirectResponse
    {
        abort_unless($workoutSession->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'client_key' => ['required', 'uuid'],
            'set_no' => ['required', 'integer', 'min:1'],
            'side' => ['nullable', Rule::in(['left', 'right'])],
            'reps_done' => ['nullable', 'integer', 'min:0'],
            'hold_sec_done' => ['nullable', 'integer', 'min:0'],
            'completed' => ['sometimes', 'boolean'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        SetEvent::createOrFirst(
            ['client_key' => $data['client_key']],
            [
                'workout_session_item_id' => $item->id,
                'set_no' => $data['set_no'],
                'side' => $data['side'] ?? null,
                'reps_done' => $data['reps_done'] ?? null,
                'hold_sec_done' => $data['hold_sec_done'] ?? null,
                'completed' => $data['completed'] ?? true,
                ...isset($data['occurred_at']) ? ['occurred_at' => $data['occurred_at']] : [],
            ],
        );

        return back();
    }
}
