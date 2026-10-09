<?php

namespace App\Services\Copilot;

use App\Models\Shoot;
use App\Models\User;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CopilotWatches
{
    public function snapshot(Shoot $shoot): array
    {
        return $shoot->only(['status', 'workflow_status', 'raw_missing_count', 'edited_missing_count',
            'photos_uploaded_at', 'editing_completed_at', 'admin_verified_at']);
    }

    public function configure(int $shootId, bool $enabled, User $user, string $grantId): array
    {
        $shoot = app(CopilotData::class)->shoot($shootId, $user);
        $snapshot = $this->snapshot($shoot);
        $existing = DB::table('copilot_watches')->where('grant_id', $grantId)->where('shoot_id', $shootId)->first();
        LockedWrite::run(fn () => DB::table('copilot_watches')->updateOrInsert(['grant_id' => $grantId, 'shoot_id' => $shootId], [
            'id' => $existing?->id ?? (string) Str::uuid(), 'user_id' => $user->id, 'enabled' => $enabled,
            'last_hash' => hash('sha256', json_encode($snapshot)), 'last_snapshot' => json_encode($snapshot),
            'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
        ]), 'copilot-watch-configure');

        return ['shoot_id' => $shootId, 'watch_enabled' => $enabled,
            'message' => $enabled ? 'Repro will check this shoot every five minutes and add an in-app notification on workflow or media-status changes.' : 'Shoot watch disabled.',
            'notification_channel' => 'Repro in-app only; ChatGPT does not receive background push messages.'];
    }

    public function check(): int
    {
        $changed = 0;
        DB::table('copilot_watches')->where('enabled', true)->orderBy('id')->chunk(100, function ($watches) use (&$changed) {
            foreach ($watches as $watch) {
                $grant = DB::table('copilot_grants')->find($watch->grant_id);
                $user = User::find($watch->user_id);
                try {
                    if (! $grant || $grant->revoked_at || ! $user || $grant->resource !== app(CopilotOAuth::class)->resource()) {
                        throw new \RuntimeException('Access revoked.');
                    }
                    app(CopilotOAuth::class)->assertEligible($user);
                    $shoot = app(CopilotData::class)->shoot($watch->shoot_id, $user);
                } catch (\Throwable $error) {
                    if ($error instanceof \Illuminate\Database\QueryException) {
                        throw $error;
                    }
                    DB::table('copilot_watches')->where('id', $watch->id)->update(['enabled' => false, 'updated_at' => now()]);

                    continue;
                }
                $snapshot = $this->snapshot($shoot);
                $hash = hash('sha256', json_encode($snapshot));
                $didChange = LockedWrite::run(fn () => DB::transaction(function () use ($watch, $hash, $snapshot, $user) {
                    $current = DB::table('copilot_watches')->find($watch->id);
                    if (! $current || ! $current->enabled) {
                        return false;
                    }
                    $grant = DB::table('copilot_grants')->find($watch->grant_id);
                    if (! $grant || $grant->revoked_at) {
                        DB::table('copilot_watches')->where('id', $watch->id)->update(['enabled' => false, 'updated_at' => now()]);

                        return false;
                    }
                    if ($current->last_hash === $hash) {
                        DB::table('copilot_watches')->where('id', $watch->id)->update(['last_checked_at' => now()]);

                        return false;
                    }
                    DB::table('copilot_watch_events')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id,
                        'grant_id' => $watch->grant_id, 'shoot_id' => $watch->shoot_id,
                        'snapshot' => json_encode(['before' => json_decode($current->last_snapshot, true), 'after' => $snapshot]), 'created_at' => now()]);
                    DB::table('copilot_watches')->where('id', $watch->id)->update(['last_hash' => $hash,
                        'last_snapshot' => json_encode($snapshot), 'last_checked_at' => now(), 'updated_at' => now()]);

                    return true;
                }), 'copilot-watch');
                if ($didChange) {
                    $changed++;
                }
            }
        });

        return $changed;
    }

    public function notifications(User $user, int $limit = 50): \Illuminate\Support\Collection
    {
        try {
            app(CopilotOAuth::class)->assertEligible($user);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return collect();
        }
        $data = app(CopilotData::class);
        if (! app(\App\Services\RolePermissionService::class)->userCan($user, 'shoots', 'view')) {
            return collect();
        }

        return DB::table('copilot_watch_events')->where('user_id', $user->id)
            ->whereIn('grant_id', DB::table('copilot_grants')->whereNull('revoked_at')->select('id'))
            ->whereIn('shoot_id', $data->query($user)->select('shoots.id'))
            ->orderByDesc('created_at')->limit($limit)->get()->map(fn ($event) => [
                'id' => 'copilot-'.$event->id, 'type' => 'system', 'action' => 'copilot_shoot_changed',
                'message' => 'A shoot you follow in Repro Copilot has a workflow or media-status change.',
                'timestamp' => $event->created_at, 'actionUrl' => '/shoots/'.$event->shoot_id,
                'actionLabel' => 'View shoot', 'shootId' => $event->shoot_id,
            ]);
    }
}
