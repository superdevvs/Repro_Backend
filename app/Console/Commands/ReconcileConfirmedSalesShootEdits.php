<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\Actions\UpdateShootAction;
use App\Services\Shoots\ShootAuthorizationSupport;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/** Narrow, dry-run-first repair of the three customer-confirmed records. */
class ReconcileConfirmedSalesShootEdits extends Command
{
    protected $signature = 'shoots:reconcile-confirmed-sales-edits {--apply} {--actor=} {--shoot=}';
    protected $description = 'Verify Botanical; reconcile Woodmire/Butterfruit without notifications, charges or travel overrides';

    public function handle(UpdateShootAction $update, ShootAuthorizationSupport $access): int
    {
        $actor = $this->option('actor') ? User::findOrFail($this->option('actor')) : null;
        if ($this->option('apply') && ! $access->hasRole($actor, ['admin', 'superadmin'])) {
            $this->error('Apply requires an existing administrator --actor.');
            return self::FAILURE;
        }
        $addresses = [2386 => '5156 woodmire', 388 => '11413 butterfruit', 2384 => '1712 botanical'];
        if ($this->option('shoot') && ! isset($addresses[(int) $this->option('shoot')])) {
            $this->error('Only the three confirmed shoot IDs are supported.');
            return self::FAILURE;
        }
        foreach ($addresses as $id => $address) {
            if ($this->option('shoot') && (int) $this->option('shoot') !== $id) continue;
            // Always re-read current state; completed repairs are no-ops.
            $shoot = Shoot::with('serviceItems.service')->findOrFail($id);
            if (! str_starts_with(strtolower(trim($shoot->address)), $address)
                || $shoot->status !== 'scheduled' || ($shoot->workflow_status ?: $shoot->status) !== 'scheduled'
                || $shoot->units()->exists()) {
                $this->error("Shoot $id identity/state changed; no repair attempted.");
                return self::FAILURE;
            }
            $payload = ['notify_client' => false, 'notify_photographer' => false, 'skip_availability_check' => false];
            if ($id === 2384) {
                if ((int) $shoot->photographer_id !== 1160 || $shoot->serviceItems->contains(fn ($item) => (int) $item->photographer_id !== 1160)) {
                    $this->error('Botanical no longer matches the confirmed Todd assignment; verify only.');
                    return self::FAILURE;
                }
                $this->info('2384: Todd verified; no changes.');
                continue;
            }
            if ($id === 388) {
                $staging = $shoot->serviceItems->where('service_id', 87);
                if ($staging->count() !== 1 || (int) $shoot->photographer_id !== 1141
                    || $shoot->serviceItems->where('service_id', 53)->count() !== 1
                    || $shoot->serviceItems->where('service_id', 53)->contains(fn ($item) => (int) $item->photographer_id !== 1141)
                    || ! in_array((int) $staging->first()->photographer_id, [0, 2264], true)
                    || (int) $staging->first()->duration_minutes !== 0) {
                    $this->error('Butterfruit assignments no longer match the confirmed repair.');
                    return self::FAILURE;
                }
                if ((int) $staging->first()->photographer_id === 2264) {
                    $this->info('388: staging already assigned; no changes.');
                    continue;
                }
                $this->assertArtist(2264, 'R/E Pro Photos Editor');
                $payload['service_photographers'] = [['service_id' => 87, 'photographer_id' => 2264]];
            } else {
                $photo = $shoot->serviceItems->where('service_id', 2);
                $floor = $shoot->serviceItems->where('service_id', 17);
                if ($photo->count() !== 1 || (int) $photo->first()->photographer_id !== 1106
                    || (float) $photo->first()->price !== 199.0
                    || ! in_array((int) $shoot->photographer_id, [989, 1106], true)
                    || $floor->count() > 1
                    || ($floor->isNotEmpty() && ((int) $floor->first()->photographer_id !== 1106 || (int) $floor->first()->duration_minutes !== 5))) {
                    $this->error('Woodmire services no longer match the confirmed repair.');
                    return self::FAILURE;
                }
                if ($floor->isNotEmpty() && (int) $shoot->photographer_id === 1106) {
                    $this->info('2386: floor plans/KK already correct; no changes.');
                    continue;
                }
                $this->assertArtist(1106, 'KK');
                $catalog = Service::findOrFail(17);
                if (strtolower(trim($catalog->name)) !== '2d floor plans' || $catalog->getShootDurationMinutes($shoot->propertySqft()) !== 5) {
                    $this->error('Floor plan catalogue must be verified at five minutes first.');
                    return self::FAILURE;
                }
                $payload['photographer_id'] = 1106;
                if ($floor->isEmpty()) {
                    $payload['services'] = $shoot->serviceItems->map(fn ($item) => ['id' => $item->service_id])->all();
                    $payload['services'][] = ['id' => 17, 'quantity' => 1, 'duration_minutes' => 5,
                        'scheduled_at' => $shoot->scheduled_at?->toIso8601String()];
                    $payload['service_photographers'] = [['service_id' => 17, 'photographer_id' => 1106]];
                }
            }
            $this->line(json_encode(['shoot_id' => $id, 'apply' => (bool) $this->option('apply'), 'payload' => $payload], JSON_THROW_ON_ERROR));
            if (! $this->option('apply')) continue;
            $request = Request::create("/api/shoots/$id", 'PATCH', $payload);
            $request->setUserResolver(fn () => $actor);
            auth()->setUser($actor);
            $result = $update->execute($request, $shoot, $actor);
            $this->info("$id: repaired through normal validation/invoice/calendar flow; notifications muted.");
            $this->line(json_encode($result->serviceItems()->get(['service_id', 'photographer_id', 'duration_minutes', 'price'])->toArray(), JSON_THROW_ON_ERROR));
        }
        return self::SUCCESS;
    }

    private function assertArtist(int $id, string $name): void
    {
        $artist = User::findOrFail($id);
        if ($artist->role !== 'photographer' || ! str_starts_with(strtolower($artist->name), strtolower($name))) {
            throw new \RuntimeException('Confirmed artist identity changed; repair stopped.');
        }
    }
}
