<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Services\Shoots\Actions\DownloadShootMediaZipAction;
use Illuminate\Http\Request;

class PublicShootMediaArchiveController extends Controller
{
    public function __construct(
        protected DownloadShootMediaZipAction $downloadShootMediaZipAction
    ) {
    }

    public function show(Request $request, Shoot $shoot)
    {
        return $this->downloadShootMediaZipAction->executePublic($request, $shoot);
    }
}
