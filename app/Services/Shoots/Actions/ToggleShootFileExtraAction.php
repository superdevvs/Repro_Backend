<?php

namespace App\Services\Shoots\Actions;

use App\Models\ShootFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ToggleShootFileExtraAction
{
    public function execute(Request $request, ShootFile $file): array
    {
        $request->validate([
            'is_extra' => 'required|boolean',
            'required_for_editing' => 'sometimes|boolean',
            'requiredForEditing' => 'sometimes|boolean',
        ]);

        $isExtra = $request->boolean('is_extra');
        $requiredForEditing = $request->has('required_for_editing')
            ? $request->boolean('required_for_editing')
            : $request->boolean('requiredForEditing', (bool) $file->required_for_editing);

        if (Schema::hasColumn('shoot_files', 'is_extra')) {
            $file->is_extra = $isExtra;
        }
        if (Schema::hasColumn('shoot_files', 'required_for_editing')) {
            $file->required_for_editing = $isExtra && $requiredForEditing;
        }
        if ($isExtra && $file->media_type === 'raw') {
            $file->media_type = 'extra';
        } elseif (!$isExtra && $file->media_type === 'extra') {
            $file->media_type = 'raw';
        }
        $file->save();

        return [
            'message' => $isExtra ? 'File marked as extra' : 'File removed from extras',
            'file' => $file->fresh(),
        ];
    }
}
