<?php

namespace App\Http\Controllers;

use App\Http\Requests\Request;
use App\Utils\Helpers;
use App\Models\BusinessSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Session;

class FirebaseController extends Controller
{
    public function subscribeToTopic(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'topic' => 'required|string',
        ]);

        $token = $request->input('token');
        $topic = $request->input('topic');

        try {
            $messaging = app('firebase.messaging');
            if($messaging){
                $messaging->subscribeToTopic($topic, $token);
                return response()->json(['message' => 'Successfully subscribed to topic'], 200);
            }
            return response()->json(['message' => 'Unauthorized'], 401);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
