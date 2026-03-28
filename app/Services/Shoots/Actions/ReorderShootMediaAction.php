<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReorderShootMediaAction
{
    public function execute(Request $request, Shoot $shoot): void
    {
        $request->validate([
            'files' => 'required|array',
            'files.*.id' => 'required|exists:shoot_files,id',
            'files.*.sort_order' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($request, $shoot) {
            foreach ($request->input('files') as $fileData) {
                $shootFile = $shoot->files()->findOrFail($fileData['id']);
                $shootFile->sort_order = $fileData['sort_order'];
                $shootFile->save();
            }
        });
    }
}
