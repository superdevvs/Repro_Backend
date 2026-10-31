<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ListingStudioRevisionTask;
use App\Services\ListingStudio\ListingStudioPaidMedia;
use App\Services\ListingStudio\ListingStudioRevisionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ListingStudioRevisionController extends Controller
{
    public function index(Request $request, ListingStudioRevisionService $service)
    {
        $input = $request->validate(['status' => ['sometimes', 'in:queued,assigned,in_progress,delivered,cancelled'], 'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'], 'assigneeId' => ['sometimes', 'integer', 'min:1'],
            'service' => ['sometimes', 'in:staging,decluttering,grass,twilight']]);
        $query = $service->visible($request->user())->with(['item.client', 'assignee', 'deliveryAttempt'])->latest();
        if (isset($input['status'])) {
            $query->where('status', $input['status']);
        }
        if (isset($input['assigneeId'])) {
            $query->where('assignee_id', $input['assigneeId']);
        }
        if (isset($input['service'])) {
            $query->whereHas('item', fn ($items) => $items->where('service', $input['service']));
        }
        $page = $query->paginate($input['perPage'] ?? 25);

        return $this->ok(['tasks' => $page->getCollection()->map(fn ($task) => $service->present($request->user(), $task))->all(),
            'pagination' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'hasMore' => $page->hasMorePages()]]);
    }

    public function show(Request $request, string $task, ListingStudioRevisionService $service)
    {
        return $this->ok(['task' => $service->present($request->user(), ListingStudioRevisionTask::findOrFail($task))]);
    }

    public function assignees(Request $request, string $task, ListingStudioRevisionService $service)
    {
        $record = ListingStudioRevisionTask::findOrFail($task);

        return $this->ok(['taskId' => $record->id, 'version' => $record->version, 'assignees' => $service->assignees($request->user(), $record)]);
    }

    public function action(Request $request, string $task, string $action, ListingStudioRevisionService $service)
    {
        abort_unless(in_array($action, ['assignment', 'start', 'hold', 'fail', 'cancel'], true), 404);
        $request->merge(['requestKey' => $request->header('Idempotency-Key')]);
        $rules = ['expectedVersion' => ['required', 'integer', 'min:1'], 'requestKey' => ['required', 'uuid']];
        if ($action === 'assignment') {
            $rules['assigneeId'] = ['present', 'nullable', 'integer'];
        }
        if (in_array($action, ['hold', 'fail', 'cancel'], true)) {
            $rules['reasonCode'] = ['required', Rule::in(ListingStudioRevisionService::REASONS)];
            $rules['note'] = ['nullable', 'string', 'max:1000'];
        }
        $input = $request->validate($rules);
        $key = $input['requestKey'];
        unset($input['requestKey']);
        $record = $service->mutate($request->user(), $task, $action === 'assignment' ? 'assign' : $action, $input, $key);

        return $this->ok(['task' => $service->present($request->user(), $record)]);
    }

    public function deliver(Request $request, string $task, ListingStudioRevisionService $service)
    {
        $request->merge(['requestKey' => $request->header('Idempotency-Key')]);
        $input = $request->validate(['photo' => ['required', 'file', 'max:10240'], 'expectedVersion' => ['required', 'integer', 'min:1'], 'requestKey' => ['required', 'uuid']]);
        $result = $service->deliver($request->user(), $task, $request->file('photo'), (int) $input['expectedVersion'], $input['requestKey']);
        $record = $result['task'];

        return $this->ok(['task' => $service->present($request->user(), $record), 'created' => $result['created']], $record->deliveryAttempt?->status === 'completed' ? 200 : 202);
    }

    public function resume(Request $request, string $task, string $attempt, ListingStudioRevisionService $service)
    {
        $input = $request->validate(['expectedVersion' => ['required', 'integer', 'min:1']]);
        $record = $service->resume($request->user(), $task, $attempt, (int) $input['expectedVersion']);

        return $this->ok(['task' => $service->present($request->user(), $record)], 202);
    }

    public function media(Request $request, string $task, string $mode, ListingStudioRevisionService $service, ListingStudioPaidMedia $media)
    {
        $record = ListingStudioRevisionTask::with('item')->findOrFail($task);
        $service->authorize($request->user(), $record);
        $input = $request->validate(['variant' => ['sometimes', Rule::in($mode === 'preview' ? ['original', 'result', 'delivery'] : ['original', 'result'])]]);
        $descriptor = match ($input['variant'] ?? 'result') {
            'original' => $record->item->source_snapshot['original'] ?? null,
            'delivery' => $record->item->status === 'completed' ? $record->item->output_descriptor : null,
            default => $record->item->source_snapshot,
        };
        abort_unless($descriptor, 404);
        $bytes = $media->read($descriptor);

        return response($mode === 'preview' ? $media->previewBytes($bytes) : $bytes)->withHeaders([
            'Content-Type' => $mode === 'preview' ? 'image/jpeg' : $descriptor['mime'], 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => $mode === 'source' ? 'attachment; filename="revision-source.'.$media->extension($descriptor['mime']).'"' : 'inline',
            'X-Content-Type-Options' => 'nosniff']);
    }

    private function ok(array $data, int $status = 200)
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }
}
