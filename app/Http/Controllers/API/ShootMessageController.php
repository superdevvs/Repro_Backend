<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootMessage;
use App\Models\User;
use App\Services\Shoots\ShootAuthorizationSupport;
use Illuminate\Http\Request;

class ShootMessageController extends Controller
{
    public function __construct(protected ShootAuthorizationSupport $authorization) {}

    public function index(Shoot $shoot, Request $request)
    {
        $this->authorization->ensureShootAccess($shoot, $request->user());
        $user = $request->user();
        $messages = $shoot->messages()
            ->when(! $this->authorization->canManageShootOperations($user), function ($query) use ($user) {
                $query->where(fn ($participants) => $participants->where('sender_id', $user->id)
                    ->orWhere('recipient_id', $user->id));
            })
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
        abort_unless($this->authorization->canSubmitShootRequest($shoot, $request->user()), 403, 'Forbidden');
        $validated = $request->validate([
            'recipient_id' => 'required|exists:users,id',
            'message' => 'required|string|max:5000',
        ]);

        $recipient = User::findOrFail($validated['recipient_id']);
        abort_unless($this->authorization->canSubmitShootRequest($shoot, $recipient), 422, 'Recipient is not a participant in this shoot.');

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
        $this->authorization->ensureShootAccess($message->shoot, $user);
        if ((string) $message->recipient_id !== (string) $user->id) {
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





