<?php

namespace App\Http\Controllers\API\Crm;

use App\Http\Controllers\Controller;
use App\Models\Recruiting\RecruitJobPosting;
use App\Services\Recruiting\RecruitApplicationIntake;
use App\Services\Recruiting\RecruitPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public careers pages (/careers and /careers/{slug}): open postings and the
 * apply form. No login. The response never says whether the person applied before.
 */
class PublicCareersController extends Controller
{
    public function __construct(
        private readonly RecruitApplicationIntake $intake,
        private readonly RecruitPresenter $present,
    ) {}

    public function index(): JsonResponse
    {
        $items = RecruitJobPosting::query()->where('status', 'open')->orderBy('state')->orderBy('title')->get();

        return response()->json(['data' => $items->map(fn ($p) => $this->present->publicPosting($p))->all()]);
    }

    public function show(string $slug): JsonResponse
    {
        $posting = RecruitJobPosting::query()->where('slug', $slug)->where('status', 'open')->firstOrFail();

        return response()->json(['posting' => $this->present->publicPosting($posting)]);
    }

    public function apply(Request $request, ?string $slug = null): JsonResponse
    {
        $posting = $slug !== null
            ? RecruitJobPosting::query()->where('slug', $slug)->where('status', 'open')->firstOrFail()
            : null;

        // Honeypot: real people never fill the hidden "website" field.
        if (trim((string) $request->input('website', '')) !== '') {
            return $this->thanks();
        }

        $data = $request->validate(RecruitApplicationIntake::rules($posting === null));
        unset($data['external_reference']);
        $this->intake->intake($data, $posting);

        return $this->thanks();
    }

    private function thanks(): JsonResponse
    {
        return response()->json(['received' => true, 'message' => 'Thanks for applying. We review every application and will be in touch.'], 201);
    }
}
