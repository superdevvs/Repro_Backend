<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\EditingRequestSubmittedMail;
use App\Models\EditingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
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
                    ->orWhereIn(DB::raw('LOWER(target_team)'), ['editor', 'editing', 'hybrid']);
            });
        } elseif ($user->role === 'editor') {
            $query->whereIn(DB::raw('LOWER(target_team)'), ['editor', 'editing', 'hybrid']);
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

        // Send email notification asynchronously - don't block the response
        try {
            $recipient = config('mail.editing_team_address', 'editing@reprophotos.com');
            $editingRequest->loadMissing(['shoot', 'requester']);
            
            // Use dispatch to queue the mail job without blocking
            // If queue is 'sync', this will still run synchronously but wrapped in try-catch
            Mail::to($recipient)->queue(new EditingRequestSubmittedMail($editingRequest));
        } catch (\Exception $e) {
            // Log the error but don't fail the request - the editing request was created successfully
            Log::error('Failed to queue editing request email', [
                'tracking_code' => $trackingCode,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Editing request submitted.',
            'data' => $editingRequest,
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $editingRequest = EditingRequest::with(['shoot', 'requester'])->findOrFail($id);

        return response()->json([
            'data' => $editingRequest,
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        $editingRequest = EditingRequest::findOrFail($id);

        // Only admins, editors, or the requester can update
        if (!in_array($user->role, ['admin', 'superadmin', 'editor']) && $user->id !== $editingRequest->requester_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'status' => 'sometimes|in:open,in_progress,completed',
            'priority' => 'sometimes|in:low,normal,high',
            'details' => 'sometimes|nullable|string',
        ]);

        $editingRequest->update($validated);

        return response()->json([
            'message' => 'Editing request updated.',
            'data' => $editingRequest->fresh(['shoot', 'requester']),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $editingRequest = EditingRequest::findOrFail($id);

        // Only admins or the requester can delete
        if (!in_array($user->role, ['admin', 'superadmin']) && $user->id !== $editingRequest->requester_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $editingRequest->delete();

        return response()->json([
            'message' => 'Editing request deleted.',
        ]);
    }
}
