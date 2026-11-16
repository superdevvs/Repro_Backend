<?php
namespace App\Console\Commands;

use App\Models\IguideLabTourSurvey;
use App\Services\IguideLab\LabTourErrors;
use App\Services\IguideLab\LabTourSurveyParseException;
use App\Services\IguideLab\LabTourSurveys;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Free re-parse of a stored survey answer (spec 5.13, D6): no HTTP, no attempt row, no usage row. */
class ReparseIguideLabTourSurvey extends Command
{
    protected $signature = 'iguide-lab:tour-survey-reparse {survey} {--force : also re-parse a completed survey that no tour run references}';
    protected $description = 'Re-parse a stored tour survey answer for free; never contacts the provider';

    public function handle(LabTourSurveys $surveys): int
    {
        $id = (string) $this->argument('survey');
        if (preg_match(LabTourErrors::UUID_RULE, $id) !== 1) {
            $this->error('A survey UUID is required.');

            return self::FAILURE;
        }
        try {
            $survey = $surveys->reparse(IguideLabTourSurvey::findOrFail(strtolower($id)), (bool) $this->option('force'));
        } catch (LabTourSurveyParseException $e) {
            $this->error('The re-parse failed with '.$e->reasonCode.' ('.$e->parseError.'); nothing was changed.');

            return self::FAILURE;
        } catch (\DomainException|HttpExceptionInterface $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ModelNotFoundException) {
            $this->error('No tour survey has this ID.');

            return self::FAILURE;
        }
        $this->line(json_encode(['version' => 'tour-survey-reparse/1', 'surveyId' => $survey->id, 'status' => $survey->status, 'errorCode' => $survey->error_code,
            'parserVersion' => $survey->parser_version, 'capturesUpdated' => $surveys->lastCapturesUpdated()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
