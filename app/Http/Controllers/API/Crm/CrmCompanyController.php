<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmCompany;
use App\Models\Crm\CrmContact;
use App\Services\Crm\CrmClientShootStats;
use App\Services\Crm\CrmCompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Companies (F4). A rep sees a company only through their own contacts at
 * it; the other reps' contacts there stay hidden. Editing is for admins.
 */
class CrmCompanyController extends CrmController
{
    public function __construct(private readonly CrmCompanyService $companies) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);

        return response()->json(['data' => $this->companies->listFor($data['q'] ?? null)->values()->all()]);
    }

    public function show(Request $request, CrmCompany $company): JsonResponse
    {
        $contacts = CrmContact::query()->where('company_id', $company->id)->orderBy('name')->limit(500)->get();
        abort_if($contacts->isEmpty() && ! $this->access()->canViewAll($this->actor($request)), 404);
        $stats = app(CrmClientShootStats::class)->forContacts($contacts);
        $viewer = $this->actor($request);

        return response()->json([
            'company' => [
                'id' => $company->id, 'name' => $company->name, 'website' => $company->website, 'phone' => $company->phone,
                'address' => $company->address, 'city' => $company->city, 'state' => $company->state, 'segment' => $company->segment, 'notes' => $company->notes,
                'can_edit' => $this->access()->canViewAll($viewer),
            ],
            'stats' => [
                'contacts' => $contacts->count(),
                'clients' => $contacts->where('stage', CrmContact::STAGE_CLIENT_POOL)->count(),
                'pipeline' => $contacts->whereIn('stage', CrmContact::PIPELINE_STAGES)->count(),
                'shoots' => collect($stats)->sum('total'),
                'last_shoot' => collect($stats)->pluck('last_shoot')->filter()->max(),
            ],
            'contacts' => $contacts->map(fn ($c) => $this->presenter()->contact($c, $viewer) + ['shoots' => $stats[$c->id] ?? null])->all(),
        ]);
    }

    public function update(Request $request, CrmCompany $company): JsonResponse
    {
        if (! $this->access()->canViewAll($this->actor($request))) {
            return response()->json(['message' => 'Only admins can edit company details.'], 403);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'], 'website' => ['nullable', 'string', 'max:190'], 'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'], 'city' => ['nullable', 'string', 'max:120'], 'state' => ['nullable', 'string', 'max:40'],
            'segment' => ['nullable', 'in:'.implode(',', CrmContact::SEGMENTS)], 'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $this->companies->update($company, $data, $this->actor($request));

        return $this->show($request, $company->fresh());
    }
}
