<?php

namespace App\Services\Studio;

use App\Models\Shoot;
use App\Models\ShootFile;
use Illuminate\Support\Str;

class AiEditedFilename
{
    public function format(Shoot $shoot, int $number): string
    {
        $address = Str::slug((string) $shoot->address) ?: 'shoot-'.$shoot->id;

        return substr($address, 0, 100).'_'.str_pad((string) max(1, $number), 3, '0', STR_PAD_LEFT).'_edited.jpg';
    }

    /** Call within the publisher transaction so concurrent editors cannot allocate the same number. */
    public function next(int $shootId): array
    {
        $shoot = Shoot::lockForUpdate()->findOrFail($shootId);
        $files = ShootFile::where('shoot_id', $shootId)->where('is_ai_edited', true)->get(['filename', 'ai_editing_metadata']);
        $number = max($files->count(), (int) $files->max(fn ($file) => $file->ai_editing_metadata['edited_sequence'] ?? 0));
        do {
            $filename = $this->format($shoot, ++$number);
        } while (ShootFile::where('shoot_id', $shootId)->where('filename', $filename)->exists());

        return ['filename' => $filename, 'number' => $number];
    }
}
