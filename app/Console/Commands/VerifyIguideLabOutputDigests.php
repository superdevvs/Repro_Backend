<?php

namespace App\Console\Commands;

use App\Services\IguideLab\LabOutputDigests;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class VerifyIguideLabOutputDigests extends Command
{
    protected $signature = 'iguide-lab:verify-output-digests {project}';
    protected $description = 'Verify and persist actual stored PNG digests for one private lab project';

    public function handle(LabOutputDigests $digests): int
    {
        try { $result = $digests->backfill((string) $this->argument('project')); }
        catch (HttpExceptionInterface $error) { $this->error($error->getMessage()); return self::FAILURE; }
        catch (ModelNotFoundException) { $this->error('Lab project or completed result not found.'); return self::FAILURE; }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
