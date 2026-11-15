<?php

namespace App\Console\Commands;

use App\Services\IguideLab\LabRunCapacity;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SetIguideLabRunLimit extends Command
{
    protected $signature = 'iguide-lab:set-run-limit {project} {limit : Absolute generation limit, at most 1024} {--expected-current= : Required current absolute limit}';
    protected $description = 'Extend one private project image limit without changing usage, capability, expiry or tour grants';

    public function handle(LabRunCapacity $capacity): int
    {
        $limit = $this->argument('limit');
        $expected = $this->option('expected-current');
        foreach ([$limit, $expected] as $value) {
            if (! is_string($value) || ! preg_match('/\A[1-9][0-9]{0,3}\z/D', $value) || (int) $value > LabRunCapacity::MAX_LIMIT) {
                $this->error('Provide an absolute limit and --expected-current, each an integer from 1 through 1024.');
                return self::FAILURE;
            }
        }
        try { $receipt = $capacity->extend((string) $this->argument('project'), (int) $limit, (int) $expected); }
        catch (HttpExceptionInterface $error) { $this->error($error->getMessage()); return self::FAILURE; }
        catch (ModelNotFoundException) { $this->error('Lab project not found.'); return self::FAILURE; }
        $this->line(json_encode($receipt, JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
