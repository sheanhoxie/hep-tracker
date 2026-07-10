<?php

namespace App\Http\Controllers;

use App\Models\Observation;
use App\Models\WorkoutSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ObservationController extends Controller
{
    public function store(Request $request, WorkoutSession $workoutSession): RedirectResponse
    {
        abort_unless($workoutSession->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'client_key' => ['required', 'uuid'],
            'workout_session_item_id' => [
                'nullable',
                'integer',
                Rule::exists('workout_session_items', 'id')->where('workout_session_id', $workoutSession->id),
            ],
            'kind' => ['required', Rule::in(['pain', 'rpe', 'note'])],
            'numeric_value' => ['nullable', 'numeric'],
            'text_value' => ['nullable', 'string'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        Observation::createOrFirst(
            ['client_key' => $data['client_key']],
            [
                'user_id' => $request->user()->id,
                'workout_session_id' => $workoutSession->id,
                'workout_session_item_id' => $data['workout_session_item_id'] ?? null,
                'kind' => $data['kind'],
                'numeric_value' => $data['numeric_value'] ?? null,
                'text_value' => $data['text_value'] ?? null,
                ...isset($data['occurred_at']) ? ['occurred_at' => $data['occurred_at']] : [],
            ],
        );

        return back();
    }
}
