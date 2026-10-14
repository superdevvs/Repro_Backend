<?php

namespace App\Services\Voice;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class VoiceGreetingService
{
    /** Frame and duration validation; rollout also decodes supplied originals before deployment. */
    public function store(string $source): string
    {
        $bytes = file_get_contents($source);
        if ($bytes === false || strlen($bytes) > 700000 || strlen($bytes) < 100) {
            throw ValidationException::withMessages(['audio' => 'Use an MP3 greeting under 700 KB.']);
        }
        $offset = 0;
        if (substr($bytes, 0, 3) === 'ID3') {
            $size = 0;
            foreach (str_split(substr($bytes, 6, 4)) as $byte) {
                $size = ($size << 7) | (ord($byte) & 127);
            }
            $offset = 10 + $size + ((ord($bytes[5]) & 16) ? 10 : 0);
        }
        $duration = 0.0;
        $frames = 0;
        while ($offset + 4 <= strlen($bytes)) {
            if (substr($bytes, $offset, 3) === 'TAG' && strlen($bytes) - $offset === 128) {
                break;
            }
            $h = unpack('N', substr($bytes, $offset, 4))[1];
            $version = ($h >> 19) & 3;
            $rateIndex = ($h >> 10) & 3;
            $bitrateIndex = ($h >> 12) & 15;
            if (($h & 0xffe00000) !== 0xffe00000 || $version === 1 || (($h >> 17) & 3) !== 1 || $rateIndex === 3 || in_array($bitrateIndex, [0, 15], true)) {
                throw ValidationException::withMessages(['audio' => 'The greeting is not a complete MP3 recording.']);
            }
            $rate = [44100, 48000, 32000][$rateIndex] / ($version === 3 ? 1 : ($version === 2 ? 2 : 4));
            $bitrates = $version === 3 ? [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320] : [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];
            $length = (int) floor(($version === 3 ? 144000 : 72000) * $bitrates[$bitrateIndex] / $rate) + (($h >> 9) & 1);
            if ($offset + $length > strlen($bytes)) {
                throw ValidationException::withMessages(['audio' => 'The MP3 recording is truncated.']);
            }
            $offset += $length;
            $duration += ($version === 3 ? 1152 : 576) / $rate;
            $frames++;
        }
        if ($frames < 10 || $duration < 1 || $duration > 90) {
            throw ValidationException::withMessages(['audio' => 'Use an MP3 greeting between 1 and 90 seconds.']);
        }
        $path = 'voice/greetings/'.hash('sha256', $bytes).'.mp3';
        abort_unless(Storage::disk('local')->put($path, $bytes, ['visibility' => 'private']), 503, 'Greeting storage failed.');

        return $path;
    }
}
