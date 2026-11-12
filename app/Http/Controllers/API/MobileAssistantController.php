<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\MobileAssistant\AssistantActions;
use App\Services\MobileAssistant\AssistantCapabilities;
use App\Services\MobileAssistant\AssistantQueries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileAssistantController extends Controller
{
    public function __construct(private AssistantCapabilities $capabilities, private AssistantQueries $queries, private AssistantActions $actions) {}

    private function response(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    private function actor(Request $request): \App\Models\User
    {
        abort_if($request->attributes->get('is_impersonating'), 403, 'Use your own account for Siri actions.');

        return $request->user();
    }

    public function capabilities(Request $request): JsonResponse
    {
        return $this->response($this->capabilities->payload($this->actor($request)));
    }

    public function query(Request $request): JsonResponse
    {
        return $this->response($this->queries->query($this->actor($request), $request->all()));
    }

    public function prepare(Request $request): JsonResponse
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'action' => 'required|string|max:64', 'params' => 'present|array']);

        return $this->response($this->actions->prepare($this->actor($request), $data['request_id'], $data['action'], $data['params']));
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        $request->validate(['confirmed' => 'required|accepted']);

        return $this->response($this->actions->confirm($this->actor($request), $id));
    }

    public function receipt(Request $request, string $id): JsonResponse
    {
        return $this->response($this->actions->receipt($this->actor($request), $id));
    }

    public function claimHandoff(Request $request, string $id): JsonResponse
    {
        return $this->response($this->actions->claimHandoff($this->actor($request), $id));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return $this->response($this->actions->cancel($this->actor($request), $id));
    }
}
