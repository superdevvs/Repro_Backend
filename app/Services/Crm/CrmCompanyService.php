<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmCompany;
use App\Models\Crm\CrmContact;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/** Shared company records and what each viewer may see of them. */
class CrmCompanyService
{
    public static function normalize(?string $name): string
    {
        $name = mb_strtolower(trim((string) $name));
        $name = preg_replace('/[^\p{L}\p{N}& ]+/u', ' ', $name) ?? $name;
        $name = preg_replace('/\b(llc|inc|ltd|co|corp|the)\b/u', ' ', $name) ?? $name;

        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    /** Find or create the company for a contact's company text. Null when blank. */
    public function resolveId(?string $name, ?string $segment = null): ?int
    {
        $name = trim((string) $name);
        $key = self::normalize($name);
        if ($key === '') {
            return null;
        }
        $id = CrmCompany::query()->where('name_normalized', $key)->value('id');
        if ($id) {
            return (int) $id;
        }
        try {
            return (int) CrmCompany::query()->create(['name' => mb_substr($name, 0, 160), 'name_normalized' => mb_substr($key, 0, 160), 'segment' => $segment])->id;
        } catch (UniqueConstraintViolationException) {
            return (int) CrmCompany::query()->where('name_normalized', $key)->value('id');
        }
    }

    /** Companies with at least one contact the viewer can see, with counts of those contacts only. */
    public function listFor(?string $q, int $limit = 200): Collection
    {
        $visible = CrmContact::query()->whereNotNull('company_id')
            ->selectRaw("company_id, COUNT(*) as contacts, SUM(CASE WHEN stage = 'client_pool' THEN 1 ELSE 0 END) as clients, SUM(CASE WHEN stage IN ('outreach','inquiry','estimate','booked') THEN 1 ELSE 0 END) as pipeline, MAX(updated_at) as last_touch")
            ->groupBy('company_id')->get()->keyBy('company_id');

        return CrmCompany::query()->whereIn('id', $visible->keys())
            ->when($q, fn ($query) => $query->where('name_normalized', 'like', '%'.self::normalize($q).'%'))
            ->orderBy('name')->limit($limit)->get()
            ->map(fn (CrmCompany $c) => [
                'id' => $c->id, 'name' => $c->name, 'website' => $c->website, 'city' => $c->city, 'state' => $c->state, 'segment' => $c->segment,
                'contacts' => (int) $visible[$c->id]->contacts,
                'clients' => (int) $visible[$c->id]->clients,
                'pipeline' => (int) $visible[$c->id]->pipeline,
                'last_touch' => $visible[$c->id]->last_touch,
            ]);
    }

    public function update(CrmCompany $company, array $data, User $actor): CrmCompany
    {
        if (isset($data['name'])) {
            $key = self::normalize($data['name']);
            $clash = CrmCompany::query()->where('name_normalized', $key)->whereKeyNot($company->id)->exists();
            if ($key === '' || $clash) {
                throw \Illuminate\Validation\ValidationException::withMessages(['name' => $key === '' ? 'Enter a name.' : 'Another company already has this name.']);
            }
            $data['name_normalized'] = $key;
        }
        $company->fill(collect($data)->only(['name', 'name_normalized', 'website', 'phone', 'address', 'city', 'state', 'segment', 'notes'])->all())->save();
        if (isset($data['name'])) {
            // Keep the contact text in step without re-running the saving hook per row.
            CrmContact::unscopedForSystem()->where('company_id', $company->id)->update(['company' => $company->name]);
        }

        return $company;
    }
}
