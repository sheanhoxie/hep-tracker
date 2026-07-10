<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        $request->user()->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
        );

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        $data = $request->validate(['endpoint' => ['required', 'string']]);

        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->noContent();
    }
}
