<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

class EmailWorkspaceEnable extends Command
{
    protected $signature = 'email-workspace:enable {--disable : Disable version 2 authoring while retaining its renderer}';
    protected $description = 'Enable or disable the compatible email workspace authoring release';

    public function handle(): int
    {
        Setting::updateOrCreate(['key' => 'email_workspace_v2_enabled'], ['value' => $this->option('disable') ? '0' : '1', 'type' => 'boolean']);
        $this->info($this->option('disable') ? 'Email workspace v2 authoring disabled.' : 'Email workspace v2 authoring enabled.');
        return self::SUCCESS;
    }
}
