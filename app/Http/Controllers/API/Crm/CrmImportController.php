<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmContact;
use App\Services\Crm\CrmImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CSV import: upload and match columns, review the result, then import the ready rows. */
class CrmImportController extends CrmController
{
    public function __construct(private readonly CrmImportService $imports) {}

    public function preview(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'import')) {
            return $denied;
        }
        $input = $this->validated($request);
        $parsed = $this->imports->parse($input['csv']);
        $mapping = $input['mapping'] ?? $this->imports->guessMapping($parsed['headers']);
        $preview = $this->imports->preview($parsed, $mapping, $input['options']);

        $viewerSeesMatches = $this->access()->canViewAll($request->user());
        $rows = array_map(function (array $row) use ($viewerSeesMatches) {
            if ($row['match'] && $viewerSeesMatches) {
                $contact = CrmContact::unscopedForSystem()->find($row['match']['contact_id']);
                $row['match']['contact'] = $contact ? ['name' => $contact->name, 'stage_label' => $contact->stageLabel(), 'assigned_rep' => $this->presenter()->user($contact->assigned_rep_id)] : null;
            } elseif ($row['match']) {
                // Reps learn only that the contact exists.
                $row['match'] = ['type' => $row['match']['type']];
            }

            return $row;
        }, $preview['rows']);

        return response()->json([
            'headers' => $parsed['headers'],
            'mapping' => $mapping,
            'fields' => CrmImportService::FIELDS,
            'counts' => $preview['counts'],
            'rows' => $rows,
            'assign_locked' => ! $this->access()->canAssign($request->user()),
        ]);
    }

    public function commit(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'import')) {
            return $denied;
        }
        $input = $this->validated($request);
        $assignTo = $request->input('assign_to');
        if ($assignTo !== null && ! $this->access()->canAssign($request->user()) && (int) $assignTo !== (int) $request->user()->id) {
            return response()->json(['message' => 'Only an admin can choose who owns imported contacts.'], 403);
        }
        if ($assignTo !== null && $this->access()->canAssign($request->user())) {
            app(\App\Services\Crm\CrmAssignmentService::class)->assertAssignable((int) $assignTo);
        }

        $parsed = $this->imports->parse($input['csv']);
        $mapping = $input['mapping'] ?? $this->imports->guessMapping($parsed['headers']);
        $result = $this->imports->commit($parsed, $mapping, $this->actor($request), $assignTo !== null ? (int) $assignTo : null, $input['options']);

        return response()->json($result);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'file' => ['nullable', 'file', 'max:5120', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel'],
            'csv' => ['nullable', 'string', 'max:5000000'],
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['nullable', 'integer', 'min:0'],
            'default_segment' => ['nullable', Rule::in(CrmContact::SEGMENTS)],
            'lead_source' => ['nullable', Rule::in(CrmContact::LEAD_SOURCES)],
            'allow_name_rows' => ['nullable', 'array'],
            'allow_name_rows.*' => ['integer'],
            'assign_to' => ['nullable', 'integer'],
        ]);

        $csv = $data['csv'] ?? ($request->hasFile('file') ? (string) file_get_contents($request->file('file')->getRealPath()) : '');
        abort_if($csv === '', 422, 'Upload a CSV file.');

        $mapping = null;
        if (! empty($data['mapping'])) {
            $mapping = [];
            foreach (CrmImportService::FIELDS as $field) {
                $mapping[$field] = isset($data['mapping'][$field]) ? (int) $data['mapping'][$field] : null;
            }
        }

        $allowName = $this->access()->canAssign($request->user()) ? ($data['allow_name_rows'] ?? []) : [];

        return [
            'csv' => $csv,
            'mapping' => $mapping,
            'options' => array_filter([
                'default_segment' => $data['default_segment'] ?? null,
                'lead_source' => $data['lead_source'] ?? null,
                'allow_name_rows' => $allowName,
            ], fn ($v) => $v !== null),
        ];
    }
}
