<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\{Shoot, ShootFile, User};
use App\Services\Shoots\{ShootAuthorizationSupport, ShootClientReleaseAccessService, ShootMediaReadService};

class ShootMediaCurationPreviewController extends Controller
{
    public function __invoke(Shoot $shoot, ShootFile $file, User $viewer, ShootAuthorizationSupport $access,
        ShootClientReleaseAccessService $release, ShootMediaReadService $media)
    {
        // The signed URL is issued only to the actual curator. Recheck current
        // ownership/assignment and safety so reassignment revokes old links.
        abort_unless($access->canManageCustomerMediaCuration($shoot, $viewer)
            && $access->canManageFileVisibility($shoot, $file, $viewer)
            && $access->isCustomerCuratableFile($file), 403, 'Forbidden');

        return $media->curationPreviewFileResponse($file, $release->isFileReleaseLocked($shoot, $file, $viewer));
    }
}
