<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootMessage;
use Illuminate\Http\Request;

class ShootMessageController extends Controller
{
    public function index(Shoot $shoot)
    {
        $messages = $shoot->messages()
            ->with([
                'sender:id,name,avatar',
                'recipient:id,name,avatar',
            ])
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $messages,
        ]);
    }

    public function store(Request $request, Shoot $shoot)
    {
        $validated = $request->validate([
            'recipient_id' => 'required|exists:users,id',
            'message' => 'required|string|max:5000',
        ]);

        $message = $shoot->messages()->create([
            'sender_id' => $request->user()->id,
            'recipient_id' => $validated['recipient_id'],
            'message' => $validated['message'],
        ]);

        return response()->json([
            'message' => 'Message sent.',
            'data' => $message->load(['sender:id,name,avatar', 'recipient:id,name,avatar']),
        ], 201);
    }

    public function markAsRead(Request $request, ShootMessage $message)
    {
        $user = $request->user();
        if ($message->recipient_id !== $user->id) {
            return response()->json([
                'message' => 'Only the recipient can mark this message as read.',
            ], 403);
        }

        if (!$message->read_at) {
            $message->read_at = now();
            $message->save();
        }

        return response()->json([
            'message' => 'Message marked as read.',
            'data' => $message,
        ]);
    }
}





