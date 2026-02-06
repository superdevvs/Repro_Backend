<?php

namespace App\Services\ReproAi\FlowEngine;

use App\Models\AiChatSession;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class FlowEngine
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function handle(
        AiChatSession $session,
        string $message,
        array $context,
        FlowHandlerInterface $handler
    ): array {
        $data = is_array($session->state_data ?? null) ? $session->state_data : [];
        $step = $session->step ?? $handler->defaultStep();

        $state = new FlowState($session, $message, $context, $data);
        $transition = $handler->handleStep($step, $state);

        $nextStep = $transition->clearStep ? null : ($transition->nextStep ?? $step);
        $nextData = $transition->data ?? $data;

        if (isset($transition->response['assistant_messages']) && is_array($transition->response['assistant_messages'])) {
            $responseStep = $transition->clearStep ? null : ($transition->nextStep ?? $step);
            $transition->response['assistant_messages'] = array_map(function ($message) use ($responseStep) {
                if (!is_array($message)) {
                    return $message;
                }

                $metadata = $message['metadata'] ?? [];
                if (!is_array($metadata)) {
                    $metadata = [];
                }

                if ($responseStep !== null && empty($metadata['step'])) {
                    $metadata['step'] = $responseStep;
                }

                $message['metadata'] = $metadata;

                return $message;
            }, $transition->response['assistant_messages']);
        }

        Log::info('AI flow transition', [
            'flow' => $handler::class,
            'session_id' => $session->id,
            'current_step' => $step,
            'next_step' => $nextStep,
            'clear_step' => $transition->clearStep,
        ]);

        if (Schema::hasColumn('ai_chat_sessions', 'step')) {
            $session->step = $nextStep;
        }
        if (Schema::hasColumn('ai_chat_sessions', 'state_data')) {
            $session->state_data = $nextData;
        }
        $session->save();

        return $transition->response;
    }
}
