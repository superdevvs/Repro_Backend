<?php

namespace App\Services\Shoots\Actions;

use App\Models\ShootFile;
use App\Services\Shoots\ShootFileAccessService;

class DownloadShootMediaAction
{
    public function __construct(protected ShootFileAccessService $fileAccess)
    {
    }

    public function execute(ShootFile $file): ?string
    {
        return $this->fileAccess->resolveFileUrl($file);
    }
}
