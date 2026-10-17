<?php

namespace App\Services\Shoots;

use App\Models\{Shoot, ShootAdditionalFile, User};
use App\Services\Scanning\ClamAvClient;
use App\Services\Shoots\Actions\UploadShootFilesAction;
use App\Support\LockedWrite;
use Illuminate\Http\{Request, UploadedFile};
use Illuminate\Support\Facades\{DB, File, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Additional files never satisfy a contracted count or become client deliverables. */
class AdditionalFileIntake
{
    public function __construct(
        private ShootAuthorizationSupport $authorization,
        private UploadIntakeResolver $intake,
        private AdditionalMediaArchive $archives,
        private AdditionalFileAccess $access,
        private ClamAvClient $scanner,
    ) {}

    public function upload(Request $request, Shoot $shoot, User $actor): array
    {
        $scope = $request->filled('shoot_service_id') ? (int) $request->input('shoot_service_id') : null;
        abort_if($scope && ! $shoot->serviceItems()->whereKey($scope)->exists(), 422, 'The selected service does not belong to this shoot.');
        abort_unless($this->authorization->canUploadShootMedia($shoot, $actor, 'additional', $scope), 403);
        $files = $request->file('files'); $files = is_array($files) ? $files : [$files];
        if (count($files) !== 1) throw ValidationException::withMessages(['files' => 'Choose one file or ZIP per upload request.']);
        $original = $files[0];
        $key = trim((string) $request->input('idempotency_key'));
        if ($key === '') throw ValidationException::withMessages(['idempotency_key' => 'An upload key is required. Retry from the file picker.']);
        $directory = storage_path('app/private/additional-intake/'.Str::uuid());
        File::makeDirectory($directory, 0770, true);
        try {
            $isArchive = $this->archives->isArchive($original);
            if ($isArchive) $entries = $this->archives->extract($original, $directory, $actor->role);
            else {
                $this->archives->validateMedia($original, $actor->role);
                $entries = [['file' => $original, 'name' => $original->getClientOriginalName()]];
            }
            // Validate and scan every entry before any media is accepted. No transaction spans I/O.
            $this->scan($original);
            foreach ($entries as $entry) if ($isArchive) $this->scan($entry['file']);
            $items = $shoot->serviceItems()->with(['service', 'shoot'])->get();
            $plans = []; $groups = [];
            foreach ($entries as $index => $entry) {
                $lane = $this->intake->laneForMimeType($this->intake->detectedMimeType($entry['file']));
                $targets = $request->boolean('route_media', true) ? $items->filter(fn ($item) =>
                    (! $scope || (int) $item->id === $scope) && $this->intake->supportsLane($item, $lane)
                    && $this->authorization->canUploadShootMedia($shoot, $actor, 'raw', $item->id)) : collect();
                $target = $targets->count() === 1 ? $targets->first() : null;
                $group = $target ? $target->id.':'.$lane : null;
                if ($group) $groups[$group][] = $index;
                $plans[$index] = $entry + ['lane' => $lane, 'target' => $target, 'group' => $group,
                    'note' => $targets->count() > 1 ? 'Multiple matching services; kept as an additional file. Select a service to send files to editing.' : 'Additional file; not sent to editing or counted toward booked photos.'];
            }
            $uploaded = []; $additional = []; $outcomes = []; $errors = [];
            if ($isArchive) $additional[] = $this->store($original, $shoot, $actor, $scope, $key.':archive', [
                'is_archive' => true, 'note' => 'Original ZIP retained. Its media files were checked individually.', 'entry_names' => array_column($entries, 'name'),
            ]);
            foreach ($groups as $group => $indexes) {
                $plan = $plans[$indexes[0]];
                $batchId = 'additional-'.hash('sha256', $key.':'.$group);
                app(ShootRawUploadBatchService::class)->prepare($shoot, $actor, [
                    'batch_id' => $batchId, 'total_files' => count($indexes), 'service_id' => $plan['target']->id, 'upload_lane' => $plan['lane'],
                ]);
                foreach ($indexes as $position => $index) $plans[$index] += ['batch_id' => $batchId, 'batch_index' => $position, 'batch_total' => count($indexes)];
            }
            foreach ($plans as $index => $plan) {
                // Recheck immediately before each write: a stale tab never grants assignment access.
                abort_unless($this->authorization->canUploadShootMedia($shoot, $actor, 'additional', $plan['target']?->id ?? $scope), 403);
                if (! $plan['target']) {
                    $record = $this->store($plan['file'], $shoot, $actor, $scope, $key.':entry:'.$index, ['note' => $plan['note'], 'archive_entry' => $isArchive ? $plan['name'] : null]);
                    $additional[] = $record;
                    $outcomes[] = ['filename' => $plan['name'], 'destination' => 'additional', 'note' => $plan['note']];
                    continue;
                }
                $childKey = 'additional-entry-'.hash('sha256', $key.':'.$index);
                $child = Request::create('/api/shoots/'.$shoot->id.'/upload', 'POST', [
                    'upload_type' => 'raw', 'upload_lane' => $plan['lane'], 'shoot_service_id' => $plan['target']->id,
                    'idempotency_key' => $childKey, 'upload_batch_id' => $plan['batch_id'],
                    'upload_batch_index' => $plan['batch_index'], 'upload_batch_total' => $plan['batch_total'],
                ], [], ['files' => [$plan['file']]]);
                $child->setUserResolver(fn () => $actor);
                $child->headers->set('Accept', 'application/json');
                // The existing capture action owns storage, quarantine, HDR grouping and editing readiness.
                $result = app(UploadShootFilesAction::class)->execute($child, $shoot, $actor);
                $accepted = $result['payload']['uploaded_files'] ?? [];
                $uploaded = array_merge($uploaded, $accepted);
                if ($accepted !== []) $outcomes[] = ['filename' => $plan['name'], 'destination' => 'raw', 'service_id' => $plan['target']->id, 'note' => 'Sent to '.$plan['target']->service->name];
                else $errors[] = ['file_name' => $plan['name'], 'message' => $result['payload']['message'] ?? 'The file could not be saved. Retry this upload.', 'retryable' => true];
            }
            $success = count($uploaded) + count($additional);
            return ['status' => $errors === [] ? 200 : ($success > 0 ? 207 : 422), 'payload' => [
                'message' => $errors === [] ? 'Files accepted.' : 'Some files need attention. Retry to finish the remaining files.',
                'uploaded_files' => $uploaded, 'additional_files' => $additional, 'file_outcomes' => $outcomes,
                'errors' => $errors, 'success_count' => $success, 'error_count' => count($errors), 'partial_success' => $errors !== [] && $success > 0,
            ]];
        } catch (HttpException $error) {
            return ['status' => $error->getStatusCode(), 'payload' => ['message' => $error->getMessage(), 'uploaded_files' => [], 'errors' => [['message' => $error->getMessage(), 'retryable' => $error->getStatusCode() >= 500]], 'success_count' => 0, 'error_count' => 1]];
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function scan(UploadedFile $file): void
    {
        try { $verdict = $this->scanner->scan($file->getRealPath()); }
        catch (\Throwable $error) { throw new HttpException(503, 'The file scanner is temporarily unavailable. Retry the upload.', $error); }
        if (! $verdict->isClean()) throw ValidationException::withMessages(['files' => 'A file failed the security scan. No files from this upload were accepted.']);
    }

    private function store(UploadedFile $file, Shoot $shoot, User $actor, ?int $scope, string $key, array $metadata): array
    {
        $identity = ['shoot_id' => $shoot->id, 'uploaded_by' => $actor->id, 'entry_key' => hash('sha256', $key)];
        if ($existing = ShootAdditionalFile::where($identity)->first()) return $this->access->present($existing);
        $path = 'shoot-additional/'.$shoot->id.'/'.Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
        $stream = fopen($file->getRealPath(), 'rb');
        if (! is_resource($stream)) throw new \RuntimeException('The additional file could not be read.');
        try { if (! Storage::disk('local')->put($path, $stream)) throw new \RuntimeException('Additional file storage failed.'); }
        finally { if (is_resource($stream)) fclose($stream); }
        $values = ['shoot_service_id' => $scope, 'filename' => $file->getClientOriginalName(), 'path' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'file_size' => $file->getSize(),
            'metadata' => $metadata + ['scan_status' => 'clean', 'scope_required' => $scope !== null]];
        try {
            $record = LockedWrite::run(fn () => DB::transaction(function () use ($identity, $shoot, $actor, $scope, $values) {
                DB::table('shoots')->where('id', $shoot->id)->update(['updated_at' => DB::raw('updated_at')]);
                abort_unless($this->authorization->canUploadShootMedia($shoot, $actor, 'additional', $scope), 403);
                return ShootAdditionalFile::firstOrCreate($identity, $values);
            }), 'shoot.additional-file.store');
            if ($record->path !== $path) Storage::disk('local')->delete($path);
            return $this->access->present($record);
        } catch (\Throwable $error) { Storage::disk('local')->delete($path); throw $error; }
    }
}
