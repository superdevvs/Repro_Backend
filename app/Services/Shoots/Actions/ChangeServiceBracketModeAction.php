<?php

namespace App\Services\Shoots\Actions;

use App\Models\ShootService;
use App\Services\Shoots\BracketModeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change the bracket size a service was shot at, and re-stack that service.
 *
 * Changing the size after files exist re-cuts every stack for the service, so it
 * cannot be a side effect of an upload or of reassigning a photographer. It is this
 * explicit operation, and it restacks only the service it was asked about: another
 * photographer's work on the same shoot is never touched.
 */
class ChangeServiceBracketModeAction
{
    public function __construct(
        private BracketModeResolver $brackets,
        private AutoStackRawFilesAction $autoStack,
    ) {}

    /**
     * @param  int|null  $bracketMode  3 or 5, or null to clear the recorded size
     * @param  bool  $restack  re-cluster the service's existing raws afterwards
     * @return array{
     *     shoot_service_id: int,
     *     previous_bracket_mode: int|null,
     *     bracket_mode: int|null,
     *     effective_bracket_mode: int|null,
     *     had_raw_files: bool,
     *     restacked: bool,
     *     restack: array{groups: int, files: int, detected_bracket_mode: int|null, updated_files: int}|null
     * }
     *
     * @throws ValidationException
     */
    public function execute(ShootService $item, ?int $bracketMode, bool $restack = true): array
    {
        $item->loadMissing(['service', 'shoot']);

        if (! $this->brackets->serviceUsesBrackets($item)) {
            throw ValidationException::withMessages([
                'bracket_mode' => ['This service does not use bracketed capture, so it has no bracket size to set.'],
            ]);
        }

        if ($bracketMode !== null && $this->brackets->normalize($bracketMode) === null) {
            throw ValidationException::withMessages([
                'bracket_mode' => ['Bracket size must be 3 or 5.'],
            ]);
        }

        $previous = $item->bracket_mode !== null ? (int) $item->bracket_mode : null;
        $hadRawFiles = $this->brackets->hasRawFiles($item);

        $result = DB::transaction(function () use ($item, $bracketMode, $restack, $previous) {
            if ($previous !== $bracketMode) {
                $item->bracket_mode = $bracketMode;
                $item->save();
            }

            // Restack even when the number did not move: the caller may be
            // repairing stacks after files were added or removed.
            return $restack
                ? $this->autoStack->execute($item->shoot, false, (int) $item->id)
                : null;
        });

        return [
            'shoot_service_id' => (int) $item->id,
            'previous_bracket_mode' => $previous,
            'bracket_mode' => $item->bracket_mode !== null ? (int) $item->bracket_mode : null,
            'effective_bracket_mode' => $this->brackets->effectiveBracketMode($item->refresh()),
            'had_raw_files' => $hadRawFiles,
            'restacked' => $result !== null,
            'restack' => $result,
        ];
    }
}
