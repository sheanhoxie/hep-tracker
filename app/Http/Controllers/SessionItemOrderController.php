<?php

namespace App\Http\Controllers;

use App\Models\WorkoutSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SessionItemOrderController extends Controller
{
    public function __invoke(Request $request, WorkoutSession $workoutSession): RedirectResponse
    {
        abort_unless($workoutSession->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'item_ids' => ['required', 'array'],
            'item_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('workout_session_items', 'id')->where('workout_session_id', $workoutSession->id),
            ],
        ]);

        DB::transaction(function () use ($workoutSession, $data): void {
            foreach ($data['item_ids'] as $sortOrder => $itemId) {
                $workoutSession->items()->whereKey($itemId)->update(['sort_order' => $sortOrder]);
            }
        });

        return back();
    }
}
