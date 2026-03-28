<?php

namespace App\Services\Shoots\Actions;

use App\Models\ShootFile;
use Illuminate\Http\Request;

class ToggleShootFileExtraAction
{
    public function execute(Request $request, ShootFile $file): array
    {
        $request->validate([
            'is_extra' => 'required|boolean',
        ]);

        $isExtra = $request->boolean('is_extra');
        $file->is_extra = $isExtra;
        $file->save();

        return [
            'message' => $isExtra ? 'File marked as extra' : 'File removed from extras',
            'file' => $file->fresh(),
        ];
    }
}
