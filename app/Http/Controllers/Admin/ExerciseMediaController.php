<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\ExerciseMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Stock media only: one row per (exercise_id, kind), enforced by the partial
 * unique index. Replace-on-write; the superseded local file is deleted
 * best-effort (an open session may briefly reference it — accepted, 9A).
 * updateOrCreate, never upsert(): Laravel's upsert can't emit the partial-index
 * predicate Postgres needs and throws 42P10 (CONTENT-ADMIN.md 7A).
 */
class ExerciseMediaController extends Controller
{
    public function store(Request $request, Exercise $exercise): RedirectResponse
    {
        $kind = (string) $request->input('kind');

        $validated = $request->validate([
            'kind' => ['required', Rule::in(['video', 'image'])],
            // url XOR file: both → prohibits fails; neither → required_without fails
            'file' => [
                'nullable', 'required_without:url', 'prohibits:url', 'file',
                ...($kind === 'video'
                    ? ['mimetypes:video/mp4,video/webm,video/quicktime', 'max:102400'] // 100MB (U-A)
                    : ['mimetypes:image/svg+xml,image/png,image/jpeg', 'max:2048']),
            ],
            'url' => ['nullable', 'required_without:file', 'url', 'max:2000'],
        ]);

        $url = isset($validated['file'])
            ? Storage::url($validated['file']->store("exercise-media/{$exercise->slug}", 'public'))
            : $validated['url'];

        $old = ExerciseMedia::query()
            ->where('exercise_id', $exercise->id)
            ->where('kind', $kind)
            ->first();

        ExerciseMedia::updateOrCreate(
            ['exercise_id' => $exercise->id, 'kind' => $kind],
            ['url' => $url, 'uploaded_by' => $request->user()->id],
        );

        // Old local file is orphaned by both upload-replace AND url-paste-over-local.
        if ($old !== null && $old->url !== $url && str_starts_with($old->url, '/storage/')) {
            Storage::disk('public')->delete(Str::after($old->url, '/storage/'));
        }

        Log::info('content.edit', [
            'action' => 'media.set',
            'actor_id' => $request->user()->id,
            'exercise_slug' => $exercise->slug,
            'kind' => $kind,
            'source' => isset($validated['file']) ? 'upload' : 'url',
            'old_url' => $old?->url,
            'new_url' => $url,
        ]);

        return back()->with('success', ucfirst($kind).' saved.');
    }

    public function destroy(Request $request, Exercise $exercise, ExerciseMedia $media): RedirectResponse
    {
        if (str_starts_with($media->url, '/storage/')) {
            Storage::disk('public')->delete(Str::after($media->url, '/storage/'));
        }
        $media->delete();

        Log::info('content.edit', [
            'action' => 'media.delete',
            'actor_id' => $request->user()->id,
            'exercise_slug' => $exercise->slug,
            'kind' => $media->kind,
            'url' => $media->url,
        ]);

        return back()->with('success', ucfirst($media->kind).' removed.');
    }
}
