<?php

namespace App\Http\Controllers\API\Crm;

use App\Http\Controllers\Controller;
use App\Models\Recruiting\RecruitJobPosting;
use App\Services\Recruiting\RecruitApplicationIntake;
use App\Services\Recruiting\RecruitReferenceConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The website's careers form (owner decision 5): the form keeps working as
 * before and also posts here with the external API key. Same intake as the
 * Dashboard's own careers pages. A repeated external_reference with the same
 * fields and files is a no-op; with anything else it is a 409 for review.
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
        // posting_slug stays in: it is part of the replay identity, and the
        // applicant service keeps only its own fields.
        try {
            $applicant = $intake->intake($data, $posting);
        } catch (RecruitReferenceConflict) {
            // Permanent for the website: its outbox routes a 409 to staff review.
            return response()->json(['received' => false, 'error' => 'reference_conflict'], 409);
        }

        return response()->json(['received' => true, 'applicant_id' => $applicant->id, 'stage' => $applicant->stage], 201);
    }
}
