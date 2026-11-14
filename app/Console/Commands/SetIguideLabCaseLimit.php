<?php

namespace App\Console\Commands;

use App\Services\IguideLab\LabCaseCapacity;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SetIguideLabCaseLimit extends Command
{
    protected $signature = 'iguide-lab:set-case-limit {project} {limit : Bounded source-record limit, 80 through 1024}';
    protected $description = 'Change one private lab source capacity without changing paid quotas or its capability';

    public function handle(LabCaseCapacity $capacity): int
    {
        $input = (string) $this->argument('limit');
        if (! preg_match('/\A[0-9]{2,4}\z/D', $input) || (int) $input < LabCaseCapacity::DEFAULT_LIMIT || (int) $input > LabCaseCapacity::MAX_LIMIT) {
            $this->error('Use an integer source limit between 80 and 1024.');
            return self::FAILURE;
        }
        try { $result = $capacity->set((string) $this->argument('project'), (int) $input); }
        catch (HttpExceptionInterface $error) { $this->error($error->getMessage()); return self::FAILURE; }
        catch (ModelNotFoundException) { $this->error('Lab project not found.'); return self::FAILURE; }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
