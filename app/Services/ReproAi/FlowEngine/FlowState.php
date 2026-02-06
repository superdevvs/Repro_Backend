<?php

namespace App\Services\ReproAi\FlowEngine;

use App\Models\AiChatSession;

class FlowState
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public AiChatSession $session,
        public string $message,
        public array $context = [],
        public array $data = [],
    ) {
    }

    public function messageLower(): string
    {
        return strtolower(trim($this->message));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function withData(array $data): self
    {
        return new self($this->session, $this->message, $this->context, $data);
    }
}
