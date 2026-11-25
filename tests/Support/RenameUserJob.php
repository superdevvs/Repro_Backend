<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RenameUserJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId, public string $name) {}

    public function handle(): void
    {
        User::whereKey($this->userId)->update(['name' => $this->name]);
    }
}
