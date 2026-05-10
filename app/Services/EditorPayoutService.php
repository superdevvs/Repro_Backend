<?php

namespace App\Services;

use App\Models\EditorPayout;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EditorPayoutService
{
    public function syncPayouts(?Carbon $start = null, ?Carbon $end = null, ?int $editorId = null): void
    {
        $rows = DB::table('shoot_service')
            ->join('shoots', 'shoots.id', '=', 'shoot_service.shoot_id')
            ->leftJoin('services', 'services.id', '=', 'shoot_service.service_id')
            ->whereNotNull('shoot_service.editor_id')
            ->when($editorId, fn ($query) => $query->where('shoot_service.editor_id', $editorId))
            ->when($start || $end, function ($query) use ($start, $end) {
                $query->where(function ($dateQuery) use ($start, $end) {
                    foreach ([
                        'shoot_service.editing_completed_at',
                        'shoots.editing_completed_at',
                        'shoots.completed_at',
                        'shoots.admin_verified_at',
                    ] as $column) {
                        $dateQuery->orWhere(function ($singleDateQuery) use ($column, $start, $end) {
                            $singleDateQuery->whereNotNull($column);

                            if ($start) {
                                $singleDateQuery->where($column, '>=', $start);
                            }

                            if ($end) {
                                $singleDateQuery->where($column, '<=', $end);
                            }
                        });
                    }
                });
            })
            ->select([
                'shoot_service.editor_id',
                'shoot_service.shoot_id',
                'shoot_service.service_id',
                'shoot_service.quantity',
                'shoot_service.editing_completed_at as service_editing_completed_at',
                'shoots.editing_completed_at as shoot_editing_completed_at',
                'shoots.completed_at',
                'shoots.admin_verified_at',
                'services.name as service_name',
                'services.photo_count as service_photo_count',
            ])
            ->orderBy('shoot_service.editor_id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $editors = User::query()
            ->whereIn('id', $rows->pluck('editor_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        foreach ($rows as $row) {
            $editor = $editors->get($row->editor_id);
            if (!$editor) {
                continue;
            }

            $completedAt = $this->resolveCompletedAt($row);
            if (!$completedAt) {
                continue;
            }

            $serviceName = trim((string) ($row->service_name ?? 'Editing Service'));
            $rate = $this->resolveRateForService($editor, $serviceName, $row->service_id);
            $quantity = $this->resolveQuantityForService(
                $serviceName,
                (int) ($row->service_photo_count ?? 0),
                (int) ($row->quantity ?? 1)
            );
            $amount = round($rate * $quantity, 2);

            $payout = EditorPayout::firstOrNew([
                'editor_id' => (int) $row->editor_id,
                'shoot_id' => (int) $row->shoot_id,
                'service_id' => $row->service_id ? (int) $row->service_id : null,
            ]);

            if (!$payout->exists) {
                $payout->fill([
                    'service_name' => $serviceName,
                    'quantity_snapshot' => max($quantity, 1),
                    'rate_snapshot' => $rate,
                    'payout_amount' => $amount,
                    'completed_at' => $completedAt,
                ]);
                $payout->save();
                continue;
            }

            // Paid records are immutable historical snapshots — only repair missing metadata.
            if ($payout->is_paid) {
                if (!$payout->completed_at || !$payout->service_name) {
                    $payout->fill([
                        'completed_at' => $payout->completed_at ?: $completedAt,
                        'service_name' => $payout->service_name ?: $serviceName,
                    ]);
                    $payout->save();
                }
                continue;
            }

            // Unpaid records refresh against the editor's current rates and the admin's
            // current scheduling quantity, so saved rate/quantity changes propagate
            // without losing the row's identity.
            $updates = [];

            if (!$payout->completed_at && $completedAt) {
                $updates['completed_at'] = $completedAt;
            }

            if (!$payout->service_name && $serviceName !== '') {
                $updates['service_name'] = $serviceName;
            }

            $resolvedQuantity = max($quantity, 1);
            if ((int) $payout->quantity_snapshot !== $resolvedQuantity) {
                $updates['quantity_snapshot'] = $resolvedQuantity;
            }

            $resolvedRate = round((float) $rate, 2);
            if (round((float) $payout->rate_snapshot, 2) !== $resolvedRate) {
                $updates['rate_snapshot'] = $resolvedRate;
            }

            $effectiveQuantity = $updates['quantity_snapshot'] ?? (int) $payout->quantity_snapshot;
            $effectiveRate = $updates['rate_snapshot'] ?? (float) $payout->rate_snapshot;
            $effectiveAmount = round($effectiveRate * $effectiveQuantity, 2);
            if (round((float) $payout->payout_amount, 2) !== $effectiveAmount) {
                $updates['payout_amount'] = $effectiveAmount;
            }

            if (!empty($updates)) {
                $payout->fill($updates);
                $payout->save();
            }
        }
    }

    public function getAdminEarnings(array $filters = []): array
    {
        [$start, $end] = $this->resolveDateRange($filters);
        $this->syncPayouts($start, $end);

        $query = $this->basePayoutQuery($filters, $start, $end)
            ->with('editor:id,name,email,metadata');

        $records = $query->orderByDesc('completed_at')->get();

        $summaries = $records
            ->groupBy('editor_id')
            ->map(function (Collection $group) {
                $editor = $group->first()?->editor;
                if (!$editor) {
                    return null;
                }

                $totalEarned = round((float) $group->sum('payout_amount'), 2);
                $unpaidAmount = round((float) $group->where('is_paid', false)->sum('payout_amount'), 2);
                $paidAmount = round((float) $group->where('is_paid', true)->sum('payout_amount'), 2);

                return [
                    'editor' => [
                        'id' => $editor->id,
                        'name' => $editor->name,
                        'email' => $editor->email,
                    ],
                    'status' => $unpaidAmount > 0 ? 'unpaid' : 'paid',
                    'service_count' => $group->count(),
                    'shoot_count' => $group->pluck('shoot_id')->unique()->count(),
                    'total_earned' => $totalEarned,
                    'unpaid_amount' => $unpaidAmount,
                    'paid_amount' => $paidAmount,
                    'latest_completed_at' => optional($group->max('completed_at'))->toISOString(),
                ];
            })
            ->filter()
            ->values();

        return [
            'period' => [
                'start' => $start?->toDateString(),
                'end' => $end?->toDateString(),
            ],
            'data' => $summaries,
            'summary' => [
                'editor_count' => $summaries->count(),
                'service_count' => $records->count(),
                'total_earned' => round((float) $records->sum('payout_amount'), 2),
                'unpaid_amount' => round((float) $records->where('is_paid', false)->sum('payout_amount'), 2),
                'paid_amount' => round((float) $records->where('is_paid', true)->sum('payout_amount'), 2),
            ],
        ];
    }

    public function getEditorDetail(User $editor, array $filters = []): array
    {
        [$start, $end] = $this->resolveDateRange($filters);
        $this->syncPayouts($start, $end, (int) $editor->id);

        $records = $this->basePayoutQuery($filters, $start, $end)
            ->where('editor_id', $editor->id)
            ->with([
                'shoot.client:id,name,email',
                'service:id,name',
                'paidBy:id,name,email,role',
            ])
            ->orderByDesc('completed_at')
            ->get();

        $currentRates = $this->getEditorRateCards($editor);

        return [
            'editor' => [
                'id' => $editor->id,
                'name' => $editor->name,
                'email' => $editor->email,
            ],
            'period' => [
                'start' => $start?->toDateString(),
                'end' => $end?->toDateString(),
            ],
            'summary' => [
                'service_count' => $records->count(),
                'shoot_count' => $records->pluck('shoot_id')->unique()->count(),
                'total_earned' => round((float) $records->sum('payout_amount'), 2),
                'unpaid_amount' => round((float) $records->where('is_paid', false)->sum('payout_amount'), 2),
                'paid_amount' => round((float) $records->where('is_paid', true)->sum('payout_amount'), 2),
                'latest_completed_at' => optional($records->max('completed_at'))->toISOString(),
            ],
            'current_rates' => $currentRates,
            'line_items' => $records->map(function (EditorPayout $payout) {
                return [
                    'id' => $payout->id,
                    'shoot_id' => $payout->shoot_id,
                    'service_id' => $payout->service_id,
                    'service_name' => $payout->service_name,
                    'quantity_snapshot' => (int) $payout->quantity_snapshot,
                    'rate_snapshot' => round((float) $payout->rate_snapshot, 2),
                    'payout_amount' => round((float) $payout->payout_amount, 2),
                    'completed_at' => optional($payout->completed_at)->toISOString(),
                    'is_paid' => (bool) $payout->is_paid,
                    'paid_at' => optional($payout->paid_at)->toISOString(),
                    'payout_batch_id' => $payout->payout_batch_id,
                    'client' => $payout->shoot?->client ? [
                        'id' => $payout->shoot->client->id,
                        'name' => $payout->shoot->client->name,
                        'email' => $payout->shoot->client->email,
                    ] : null,
                    'shoot' => $payout->shoot ? [
                        'id' => $payout->shoot->id,
                        'address' => $payout->shoot->address,
                        'city' => $payout->shoot->city,
                        'state' => $payout->shoot->state,
                        'zip' => $payout->shoot->zip,
                        'scheduled_date' => optional($payout->shoot->scheduled_date)->toDateString(),
                    ] : null,
                    'paid_by' => $payout->paidBy ? [
                        'id' => $payout->paidBy->id,
                        'name' => $payout->paidBy->name,
                        'email' => $payout->paidBy->email,
                        'role' => $payout->paidBy->role,
                    ] : null,
                ];
            })->values(),
            'timeline' => $records
                ->filter(fn (EditorPayout $payout) => $payout->paid_at)
                ->map(fn (EditorPayout $payout) => [
                    'id' => $payout->id,
                    'label' => 'Marked paid',
                    'timestamp' => $payout->paid_at?->toISOString(),
                    'service_name' => $payout->service_name,
                    'actor' => $payout->paidBy ? [
                        'id' => $payout->paidBy->id,
                        'name' => $payout->paidBy->name,
                        'email' => $payout->paidBy->email,
                        'role' => $payout->paidBy->role,
                    ] : null,
                ])
                ->values(),
        ];
    }

    public function markPaid(array $payoutIds, User $actor): array
    {
        $ids = collect($payoutIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [
                'updated_count' => 0,
                'total_paid' => 0,
                'payout_batch_id' => null,
            ];
        }

        $batchId = (string) Str::uuid();
        $records = EditorPayout::query()->whereIn('id', $ids)->get();

        $updatedCount = 0;
        $totalPaid = 0.0;

        foreach ($records as $record) {
            if ($record->is_paid) {
                continue;
            }

            $record->update([
                'is_paid' => true,
                'paid_at' => now(),
                'paid_by' => $actor->id,
                'payout_batch_id' => $batchId,
            ]);
            $updatedCount++;
            $totalPaid += (float) $record->payout_amount;
        }

        return [
            'updated_count' => $updatedCount,
            'total_paid' => round($totalPaid, 2),
            'payout_batch_id' => $batchId,
        ];
    }

    public function buildEmailSummaries(?Carbon $start = null, ?Carbon $end = null): Collection
    {
        $data = $this->getAdminEarnings([
            'start' => $start?->toDateString(),
            'end' => $end?->toDateString(),
        ]);

        return collect($data['data'] ?? [])->map(function (array $summary) {
            return [
                'id' => $summary['editor']['id'],
                'name' => $summary['editor']['name'],
                'email' => $summary['editor']['email'],
                'role' => 'editor',
                'shoot_count' => $summary['shoot_count'],
                'service_count' => $summary['service_count'],
                'gross_total' => $summary['total_earned'],
                'average_value' => $summary['service_count'] > 0
                    ? round($summary['total_earned'] / $summary['service_count'], 2)
                    : 0,
                'commission_rate' => null,
                'commission_total' => null,
                'unpaid_amount' => $summary['unpaid_amount'],
                'paid_amount' => $summary['paid_amount'],
            ];
        });
    }

    private function basePayoutQuery(array $filters, ?Carbon $start = null, ?Carbon $end = null): Builder
    {
        return EditorPayout::query()
            ->when(!empty($filters['status']), function (Builder $query) use ($filters) {
                if ($filters['status'] === 'paid') {
                    $query->where('is_paid', true);
                }

                if ($filters['status'] === 'unpaid') {
                    $query->where('is_paid', false);
                }
            })
            ->when(!empty($filters['search']), function (Builder $query) use ($filters) {
                $search = trim((string) $filters['search']);
                $query->whereHas('editor', function (Builder $editorQuery) use ($search) {
                    $editorQuery
                        ->where('name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                });
            })
            ->when($start, fn (Builder $query) => $query->where('completed_at', '>=', $start))
            ->when($end, fn (Builder $query) => $query->where('completed_at', '<=', $end))
            ->when(!empty($filters['service_type']), function (Builder $query) use ($filters) {
                $type = strtolower((string) $filters['service_type']);

                $query->where(function (Builder $typeQuery) use ($type) {
                    match ($type) {
                        'photo' => $typeQuery->where('service_name', 'like', '%photo%')->orWhere('service_name', 'like', '%hdr%')->orWhere('service_name', 'like', '%twilight%'),
                        'video' => $typeQuery->where('service_name', 'like', '%video%'),
                        'virtual_staging' => $typeQuery->where('service_name', 'like', '%virtual%')->where('service_name', 'like', '%staging%'),
                        'floorplan' => $typeQuery->where('service_name', 'like', '%floorplan%'),
                        default => $typeQuery->where('service_name', 'like', '%' . $type . '%'),
                    };
                });
            });
    }

    private function resolveDateRange(array $filters): array
    {
        $defaultStart = Carbon::now()->startOfWeek(Carbon::MONDAY)->subWeek();
        $defaultEnd = $defaultStart->copy()->endOfWeek(Carbon::SUNDAY);

        $start = !empty($filters['start']) ? Carbon::parse($filters['start'])->startOfDay() : $defaultStart;
        $end = !empty($filters['end']) ? Carbon::parse($filters['end'])->endOfDay() : $defaultEnd;

        return [$start, $end];
    }

    private function resolveCompletedAt(object $row): ?Carbon
    {
        foreach ([
            $row->service_editing_completed_at ?? null,
            $row->shoot_editing_completed_at ?? null,
            $row->completed_at ?? null,
            $row->admin_verified_at ?? null,
        ] as $value) {
            if ($value) {
                return Carbon::parse($value);
            }
        }

        return null;
    }

    private function resolveRateForService(User $editor, string $serviceName, $serviceId): float
    {
        $metadata = is_array($editor->metadata) ? $editor->metadata : [];
        $serviceRates = collect($metadata['service_rates'] ?? $metadata['serviceRates'] ?? $metadata['editing_service_rates'] ?? [])
            ->filter(fn ($rate) => is_array($rate))
            ->map(function (array $rate) {
                return [
                    'service_id' => isset($rate['service_id']) ? (string) $rate['service_id'] : (isset($rate['serviceId']) ? (string) $rate['serviceId'] : null),
                    'service_name' => trim((string) ($rate['service_name'] ?? $rate['serviceName'] ?? '')),
                    'rate' => (float) ($rate['rate'] ?? 0),
                ];
            });

        $stringServiceId = $serviceId !== null ? (string) $serviceId : null;

        if ($stringServiceId) {
            $exact = $serviceRates->first(fn (array $rate) => $rate['service_id'] && $rate['service_id'] === $stringServiceId);
            if ($exact) {
                return round((float) $exact['rate'], 2);
            }
        }

        $normalizedName = $this->normalizeServiceName($serviceName);

        $bestMatch = $serviceRates
            ->map(function (array $rate) use ($normalizedName) {
                $candidate = $this->normalizeServiceName($rate['service_name']);
                $score = $candidate === $normalizedName
                    ? 3
                    : (($candidate && str_contains($candidate, $normalizedName)) || ($normalizedName && str_contains($normalizedName, $candidate)) ? 2 : 0);

                return [
                    'score' => $score,
                    'rate' => $rate['rate'],
                ];
            })
            ->sortByDesc('score')
            ->first();

        if ($bestMatch && $bestMatch['score'] > 0) {
            return round((float) $bestMatch['rate'], 2);
        }

        $lookup = [
            'virtual_staging_rate' => '/virtual\s*staging/i',
            'video_edit_rate' => '/video/i',
            'floorplan_rate' => '/floorplan/i',
            'photo_edit_rate' => '/photo|hdr|twilight/i',
        ];

        foreach ($lookup as $field => $pattern) {
            if (preg_match($pattern, $serviceName)) {
                return round((float) ($metadata[$field] ?? 0), 2);
            }
        }

        return round((float) ($metadata['other_rate'] ?? 0), 2);
    }

    private function resolveQuantityForService(string $serviceName, int $servicePhotoCount, int $fallbackQuantity): int
    {
        // Prefer the admin-configured photo count on the service row when present —
        // this is the canonical bundle size (e.g. "25 HDR Photos" → 25).
        if ($servicePhotoCount > 0) {
            return $servicePhotoCount;
        }

        // Otherwise try to extract the bundle size embedded in the service name,
        // covering common forms like "25 HDR Photos", "40 HDR", "30 Images".
        if (preg_match('/(\d+)\s*(?:[a-z]+\s+){0,3}(?:photo|image|hdr)/i', $serviceName, $matches)) {
            return max((int) $matches[1], 1);
        }

        return max($fallbackQuantity, 1);
    }

    private function normalizeServiceName(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/i', ' ', strtolower($value)));
    }

    private function getEditorRateCards(User $editor): array
    {
        $metadata = is_array($editor->metadata) ? $editor->metadata : [];
        $serviceRates = collect($metadata['service_rates'] ?? $metadata['serviceRates'] ?? $metadata['editing_service_rates'] ?? [])
            ->filter(fn ($rate) => is_array($rate))
            ->map(fn (array $rate) => [
                'service_id' => $rate['service_id'] ?? $rate['serviceId'] ?? null,
                'service_name' => trim((string) ($rate['service_name'] ?? $rate['serviceName'] ?? '')),
                'rate' => round((float) ($rate['rate'] ?? 0), 2),
            ])
            ->values();

        return [
            'photo_edit_rate' => round((float) ($metadata['photo_edit_rate'] ?? 0), 2),
            'video_edit_rate' => round((float) ($metadata['video_edit_rate'] ?? 0), 2),
            'floorplan_rate' => round((float) ($metadata['floorplan_rate'] ?? 0), 2),
            'virtual_staging_rate' => round((float) ($metadata['virtual_staging_rate'] ?? 0), 2),
            'other_rate' => round((float) ($metadata['other_rate'] ?? 0), 2),
            'service_rates' => $serviceRates,
        ];
    }
}
