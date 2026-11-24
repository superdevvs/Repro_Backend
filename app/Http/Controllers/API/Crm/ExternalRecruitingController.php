<?php

namespace App\Http\Controllers\API\Crm;

use App\Http\Controllers\Controller;
use App\Models\Recruiting\RecruitJobPosting;
use App\Services\Recruiting\RecruitApplicationIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The website's careers form (owner decision 5): the form keeps working as
 * before and also posts here with the external API key. Same intake as the
 * Dashboard's own careers pages; a repeated external_reference is a no-op.
 */
class ExternalRecruitingController extends Controller
{
    public function store(Request $request, RecruitApplicationIntake $intake): JsonResponse
    {
        $data = $request->validate(RecruitApplicationIntake::rules(false) + [
            'posting_slug' => ['nullable', 'string', 'max:190'],
        ]);
        $posting = ! empty($data['posting_slug'])
            ? RecruitJobPosting::query()->where('slug', $data['posting_slug'])->first()
            : null;
        if (! $posting && empty($data['role'])) {
            return response()->json(['message' => 'Send role (photographer or sales_rep) or a posting_slug.', 'errors' => ['role' => ['Required without a posting.']]], 422);
        }
        unset($data['posting_slug']);
        $applicant = $intake->intake($data, $posting);

        return response()->json(['received' => true, 'applicant_id' => $applicant->id, 'stage' => $applicant->stage], 201);
    }
}
