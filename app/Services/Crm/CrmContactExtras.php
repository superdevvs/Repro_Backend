<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;
use Illuminate\Support\Facades\Schema;

/**
 * Extra fields for a single contact view, contributed by later features
 * (next event, drip status, email engagement). Each part checks its own table
 * so the contact screen keeps working if a feature is switched off.
 */
class CrmContactExtras
{
    /** @var array<string, bool> */
    private array $tables = [];

    public function for(CrmContact $contact): array
    {
        $extra = [];

        if ($this->has('crm_events')) {
            $extra['next_event'] = app(CrmEventPresenter::class)->nextFor($contact);
        }
        if ($this->has('crm_drip_enrollments')) {
            $extra['drip'] = app(\App\Services\Crm\Email\CrmDripPresenter::class)->activeFor($contact);
        }

        return $extra;
    }

    private function has(string $table): bool
    {
        return $this->tables[$table] ??= Schema::hasTable($table);
    }
}
