<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use App\Models\PaymentServiceAllocation;
use Illuminate\Support\Collection;

/** A bounded, request-local snapshot used only while presenting one shoot-list page. */
final class ShootListReadContext
{
    private const KEY = 'shoot_list_read_context';

    private array $shoots = [];
    private Collection $items;
    private Collection $people;
    private Collection $allocations;
    private array $itemIds = [];
    private array $personIds = [];
    private array $editingRequired = [];
    private array $raw = [];
    private array $edited = [];

    public static function current(): ?self
    {
        return request()->attributes->get(self::KEY);
    }

    public static function run(Collection $shoots, callable $present): mixed
    {
        $request = request();
        $previous = $request->attributes->get(self::KEY);
        $context = new self;
        $context->prepare($shoots);
        $request->attributes->set(self::KEY, $context);
        try {
            return $present();
        } finally {
            $previous === null ? $request->attributes->remove(self::KEY) : $request->attributes->set(self::KEY, $previous);
        }
    }

    public function serviceItems(Shoot $shoot): ?Collection
    {
        return isset($this->shoots[$shoot->id]) ? $this->items->get($shoot->id, collect()) : null;
    }

    public function requiresEditing(int|string $itemId): ?bool
    {
        return $this->editingRequired[$itemId] ?? null;
    }

    public function users(Collection|array $ids): Collection
    {
        $ids = collect($ids)->filter()->map(fn ($id) => (int) $id)->unique()->all();
        $missing = array_diff($ids, $this->personIds);
        if ($missing !== []) {
            // Legacy assignments can refer to someone outside the current
            // service rows. Preserve the normal lookup rather than silently
            // omitting that person, and remember misses (including deletions).
            $this->people = $this->people->union(User::whereIn('id', $missing)->get()->keyBy('id'));
            $this->personIds = array_merge($this->personIds, $missing);
        }
        return $this->people->only($ids)->keyBy('id');
    }

    public function allocations(Collection $items): ?Collection
    {
        $ids = $items->pluck('id')->all();
        if (array_diff($ids, $this->itemIds) !== []) return null;
        return $this->allocations->whereIn('shoot_service_id', $ids);
    }

    /** Null means outside this page; an empty array is a known absence of files. */
    public function raw(Shoot $shoot): ?array
    {
        return isset($this->shoots[$shoot->id]) ? ($this->raw[$shoot->id] ?? []) : null;
    }

    public function edited(Shoot $shoot): ?array
    {
        return isset($this->shoots[$shoot->id]) ? ($this->edited[$shoot->id] ?? []) : null;
    }

    private function prepare(Collection $shoots): void
    {
        $this->shoots = $shoots->keyBy('id')->all();
        $ids = array_keys($this->shoots);
        $this->items = collect();
        $this->people = collect();
        $this->allocations = collect();
        if ($ids === []) return;

        // The list's include_payments projection selects only a few columns.
        // Match the presenter's full payment/refund hydration in one batch;
        // loadMissing would retain that incomplete payment projection.
        (new \Illuminate\Database\Eloquent\Collection($shoots->all()))->load('payments.refunds');
        $items = ShootService::whereIn('shoot_id', $ids)
            ->with(['service.category', 'service.sqftRanges', 'unit', 'photographer', 'editor', 'unlockedBy'])
            ->orderByRaw('scheduled_at is null')->orderBy('scheduled_at')->orderBy('id')->get();
        foreach ($items as $item) $item->setRelation('shoot', $this->shoots[$item->shoot_id]);
        $this->items = $items->groupBy('shoot_id');
        $this->itemIds = $items->modelKeys();
        $this->allocations = PaymentServiceAllocation::with('payment.refunds')->whereIn('shoot_service_id', $this->itemIds)->get();

        $personIds = $items->flatMap(fn ($item) => [$item->photographer_id, $item->editor_id, $item->video_editor_id])
            ->merge($shoots->flatMap(fn ($shoot) => [$shoot->photographer_id, $shoot->editor_id, $shoot->rep_id]));
        $this->personIds = $personIds->filter()->map(fn ($id) => (int) $id)->unique()->all();
        $this->people = User::whereIn('id', $this->personIds)->get()->keyBy('id');

        $direct = ShootFile::whereIn('shoot_service_id', $items->pluck('id'))
            ->where('required_for_editing', true)->distinct()->pluck('shoot_service_id')->flip();
        $legacy = ShootFile::whereIn('shoot_id', $ids)->whereNull('shoot_service_id')
            ->where('required_for_editing', true)->get(['shoot_id', 'media_type', 'file_type', 'filename'])->groupBy('shoot_id');
        $editing = app(ShootEditingAssignmentService::class);
        foreach ($items as $item) {
            $required = $item->service?->requiresEditing() ?? false;
            if (! $required && $direct->has($item->id)) $required = true;
            if (! $required) {
                $lanes = $legacy->get($item->shoot_id, collect())->map(fn ($file) => $editing->getFileLane($file))->unique();
                foreach ($lanes as $lane) {
                    $compatible = $this->items->get($item->shoot_id)->filter(fn ($candidate) => $lane === ShootEditingAssignmentService::LANE_VIDEO
                        ? ($candidate->service?->supportsVideoIntake() ?? false)
                        : ($candidate->service?->supportsPhotoIntake() ?? false));
                    if ($compatible->count() === 1 && (int) $compatible->first()->id === (int) $item->id) $required = true;
                }
            }
            $this->editingRequired[$item->id] = $required;
        }

        foreach (ShootFile::whereIn('shoot_id', $ids)->where('workflow_stage', ShootFile::STAGE_TODO)
            ->select('shoot_id')->selectRaw('MAX(created_at) as latest_created_at')->groupBy('shoot_id')->get() as $row) {
            $this->raw[$row->shoot_id] = ['exists' => true, 'latest_created_at' => $row->latest_created_at];
        }
        foreach (ShootFile::whereIn('shoot_id', $ids)->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])
            ->where(fn ($query) => $query->whereNull('flag_reason')->orWhere('flag_reason', ''))
            ->select('shoot_id')->selectRaw('MAX(created_at) as latest_created_at')->groupBy('shoot_id')->get() as $row) {
            $this->edited[$row->shoot_id] = ['exists' => true, 'latest_created_at' => $row->latest_created_at];
        }
    }
}
