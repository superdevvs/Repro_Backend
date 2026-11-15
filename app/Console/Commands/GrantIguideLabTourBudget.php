<?php
namespace App\Console\Commands;

use App\Services\IguideLab\LabTourProcessingBudget;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class GrantIguideLabTourBudget extends Command
{
    protected $signature='iguide-lab:grant-tour-budget {project} {--captures=} {--analysis=} {--section=} {--image=} {--segmentation=} {--composition=} {--evaluation=} {--expires= : UTC epoch second, no later than project expiry}';
    protected $description='Create one finite private session allocation; never changes project image quota or source capacity';
    public function handle(LabTourProcessingBudget $budgets):int
    {
        $values=[];foreach(array_merge(['captures','expires'],array_keys(LabTourProcessingBudget::MAX_PER_ITEM)) as $name){
            $value=$this->option($name);if(!is_string($value)||!preg_match('/\A[0-9]{1,10}\z/D',$value)){$this->error('Every bounded count and expiry must be explicit.');return self::FAILURE;}$values[$name]=(int)$value;
        }
        $limits=array_intersect_key($values,LabTourProcessingBudget::MAX_PER_ITEM);
        try{$result=$budgets->create((string)$this->argument('project'),$values['captures'],$limits,$values['expires']);}
        catch(HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException $e){$this->error($e->getMessage());return self::FAILURE;}
        $this->line(json_encode($result,JSON_THROW_ON_ERROR));return self::SUCCESS;
    }
}
