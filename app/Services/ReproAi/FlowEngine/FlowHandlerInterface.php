<?php

namespace App\Services\ReproAi\FlowEngine;

interface FlowHandlerInterface
{
    public function defaultStep(): string;

    public function handleStep(string $step, FlowState $state): FlowTransition;
}
