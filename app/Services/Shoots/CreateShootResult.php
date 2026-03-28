<?php

namespace App\Services\Shoots;

use App\Models\Shoot;

class CreateShootResult
{
    public function __construct(
        public Shoot $shoot,
        public bool $treatAsClientRequest,
        public ?\DateTime $scheduledAt
    ) {
    }
}
