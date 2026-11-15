<?php
namespace App\Console\Commands;

use App\Services\IguideLab\LabTourProcessingBudget;
use Illuminate\Console\Command;

class RevokeIguideLabTourBudget extends Command
{
    protected $signature='iguide-lab:revoke-tour-budget {budget}';
    protected $description='Revoke future claims against a private tour budget without refunds or erasing retained work';
    public function handle(LabTourProcessingBudget $budgets):int
    {
        try{$budgets->revoke((string)$this->argument('budget'));}
        catch(\Illuminate\Database\Eloquent\ModelNotFoundException){$this->error('Budget not found.');return self::FAILURE;}
        $this->line('Budget revoked; no allowance or submitted work was refunded.');return self::SUCCESS;
    }
}
