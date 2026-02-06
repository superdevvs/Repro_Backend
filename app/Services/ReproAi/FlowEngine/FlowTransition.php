<?php

namespace App\Services\ReproAi\FlowEngine;

class FlowTransition
{
    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public array $response,
        public ?string $nextStep = null,
        public ?array $data = null,
        public bool $clearStep = false,
    ) {
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>|null  $data
     */
    public static function stay(array $response, ?array $data = null): self
    {
        return new self($response, null, $data, false);
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>|null  $data
     */
    public static function next(string $step, array $response, ?array $data = null): self
    {
        return new self($response, $step, $data, false);
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>|null  $data
     */
    public static function clear(array $response, ?array $data = null): self
    {
        return new self($response, null, $data, true);
    }
}
