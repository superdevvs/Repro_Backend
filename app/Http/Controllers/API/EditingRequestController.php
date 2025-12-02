<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\EditingRequestSubmittedMail;
use App\Models\EditingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EditingRequestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = EditingRequest::with(['shoot:id,address,scheduled_date', 'requester:id,name,email'])
            ->orderByDesc('created_at');

        if ($user->role === 'salesRep') {
            $query->where(function ($builder) use ($user) {
                $builder->where('requester_id', $user->id)
                    ->orWhereIn('target_team', ['editor', 'hybrid']);
            });
        } elseif ($user->role === 'editor') {
            $query->whereIn('target_team', ['editor', 'hybrid']);
        }

        $requests = $query->limit(50)->get();

        return response()->json([
            'data' => $requests,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'shoot_id' => 'nullable|exists:shoots,id',
            'summary' => 'required|string|max:255',
            'details' => 'nullable|string',
            'priority' => 'required|in:low,normal,high',
            'target_team' => 'required|in:editor,admin,hybrid',
        ]);

        $trackingCode = 'ER-' . Str::upper(Str::random(8));

        $editingRequest = EditingRequest::create([
            'shoot_id' => $validated['shoot_id'] ?? null,
            'requester_id' => $user->id,
            'tracking_code' => $trackingCode,
            'summary' => $validated['summary'],
            'details' => $validated['details'] ?? null,
            'priority' => $validated['priority'],
            'target_team' => $validated['target_team'],
            'status' => 'open',
        ]);

        $recipient = config('mail.editing_team_address', 'editing@reprophotos.com');
        Mail::to($recipient)->queue(new EditingRequestSubmittedMail($editingRequest->fresh(['shoot', 'requester'])));

        return response()->json([
            'message' => 'Editing request submitted.',
            'data' => $editingRequest,
        ], 201);
    }
}

