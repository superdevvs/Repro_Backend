<?php

namespace App\Services\Studio;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Intervention\Image\ImageManager;
use RuntimeException;

/** Deterministic processing of an authorized workspace version. No provider calls. */
class CustomPhotoEdits
{
    public static function validate(array $recipe): array
    {
        $rules = ['exposure' => ['sometimes', 'numeric', 'between:-2,2'], 'rotation' => ['sometimes', Rule::in([0, 90, 180, 270])],
            'flip' => ['sometimes', 'boolean'], 'sharpen' => ['sometimes', 'integer', 'between:0,30'],
            'denoise' => ['sometimes', 'integer', 'between:0,3'], 'caption' => ['sometimes', 'nullable', 'string', 'max:100'],
            'crop' => ['sometimes', 'array:x,y,width,height'], 'blur' => ['sometimes', 'array', 'max:20'],
            'marks' => ['sometimes', 'array', 'max:30'], 'marks.*' => ['array:kind,x,y,width,height,label'],
            'marks.*.kind' => ['required', Rule::in(['pin', 'boundary'])], 'marks.*.label' => ['sometimes', 'nullable', 'string', 'max:40']];
        foreach (['contrast', 'temperature', 'tint', 'saturation', 'highlights', 'shadows', 'whites', 'blacks'] as $key) {
            $rules[$key] = ['sometimes', 'numeric', 'between:-100,100'];
        }
        $rules['color'] = ['sometimes', 'array:red,orange,yellow,green,aqua,blue,purple,magenta'];
        $rules['logo'] = ['sometimes', 'array:id,x,y,width,opacity'];
        $rules['logo.id'] = ['required_with:logo', 'uuid'];
        $rules['logo.x'] = ['required_with:logo', 'numeric', 'between:0,1'];
        $rules['logo.y'] = ['required_with:logo', 'numeric', 'between:0,1'];
        $rules['logo.width'] = ['required_with:logo', 'numeric', 'between:0.01,0.5'];
        $rules['logo.opacity'] = ['sometimes', 'numeric', 'between:0,100'];
        foreach (['red', 'orange', 'yellow', 'green', 'aqua', 'blue', 'purple', 'magenta'] as $band) {
            $rules['color.'.$band] = ['sometimes', 'array:hue,saturation,lightness'];
            $rules['color.'.$band.'.hue'] = ['sometimes', 'numeric', 'between:-30,30'];
            $rules['color.'.$band.'.saturation'] = ['sometimes', 'numeric', 'between:-100,100'];
            $rules['color.'.$band.'.lightness'] = ['sometimes', 'numeric', 'between:-100,100'];
        }
        foreach (['crop', 'blur.*', 'marks.*'] as $prefix) {
            if ($prefix === 'blur.*') {
                $rules[$prefix] = ['array:x,y,width,height'];
            }
            foreach (['x', 'y', 'width', 'height'] as $field) {
                $rules[$prefix.'.'.$field] = ['required_with:'.$prefix, 'numeric', in_array($field, ['width', 'height']) ? 'gt:0' : 'min:0', 'max:1'];
            }
        }
        $result = Validator::make($recipe, $rules)->validate();
        foreach (array_merge(isset($result['crop']) ? [$result['crop']] : [], $result['blur'] ?? [], $result['marks'] ?? []) as $box) {
            if ($box['x'] + $box['width'] > 1.00001 || $box['y'] + $box['height'] > 1.00001) {
                throw \Illuminate\Validation\ValidationException::withMessages(['edits' => 'Every selected area must fit inside the photo.']);
            }
        }

        return $result;
    }

    public function apply(string $bytes, array $recipe, ?string $logoBytes = null): string
    {
        $recipe = self::validate($recipe);
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] * $size[1] > 40000000) {
            throw new RuntimeException('This photo exceeds the 40 megapixel editing limit.');
        }
        $image = ImageManager::gd()->read($bytes)->orient();
        $gd = $image->core()->native();
        imagepalettetotruecolor($gd);
        $hasColor = array_intersect_key($recipe, array_flip(['exposure', 'contrast', 'temperature', 'tint', 'saturation', 'highlights', 'shadows', 'whites', 'blacks']));
        if (array_filter($hasColor) || ! empty($recipe['color'])) {
            $exposure = 2 ** ($recipe['exposure'] ?? 0);
            $contrast = 1 + ($recipe['contrast'] ?? 0) / 100;
            $saturation = 1 + ($recipe['saturation'] ?? 0) / 100;
            $temperature = ($recipe['temperature'] ?? 0) * .3;
            $tint = ($recipe['tint'] ?? 0) * .3;
            for ($y = 0; $y < imagesy($gd); $y++) {
                for ($x = 0; $x < imagesx($gd); $x++) {
                    $pixel = imagecolorat($gd, $x, $y);
                    $r = ($pixel >> 16) & 255;
                    $g = ($pixel >> 8) & 255;
                    $b = $pixel & 255;
                    $luma = .2126 * $r + .7152 * $g + .0722 * $b;
                    $n = $luma / 255;
                    $tone = (($recipe['shadows'] ?? 0) * (1 - $n) ** 2 + ($recipe['highlights'] ?? 0) * $n ** 2
                        + ($recipe['blacks'] ?? 0) * max(0, 1 - 4 * $n) + ($recipe['whites'] ?? 0) * max(0, 4 * $n - 3)) * .6;
                    $channels = [];
                    foreach ([$r + $temperature + $tint / 2, $g - $tint / 2, $b - $temperature + $tint / 2] as $channel) {
                        $value = (($luma + ($channel - $luma) * $saturation) * $exposure - 127.5) * $contrast + 127.5 + $tone;
                        $channels[] = (int) round(max(0, min(255, $value)));
                    }
                    if (! empty($recipe['color'])) {
                        $channels = $this->selectiveColor($channels, $recipe['color']);
                    }
                    imagesetpixel($gd, $x, $y, ($channels[0] << 16) | ($channels[1] << 8) | $channels[2]);
                }
            }
        }
        for ($i = 0; $i < ($recipe['denoise'] ?? 0); $i++) {
            imagefilter($gd, IMG_FILTER_SMOOTH, 2);
        }
        if ($amount = $recipe['sharpen'] ?? 0) {
            $a = $amount / 30;
            imageconvolution($gd, [[0, -$a, 0], [-$a, 1 + 4 * $a, -$a], [0, -$a, 0]], 1, 0);
        }
        // Areas use source coordinates. Blur and marks precede crop/rotation so they remain attached to the property.
        foreach ($recipe['blur'] ?? [] as $area) {
            [$x, $y, $w, $h] = $this->pixels($gd, $area);
            $small = imagecreatetruecolor(max(1, (int) ceil($w / 18)), max(1, (int) ceil($h / 18)));
            imagecopyresampled($small, $gd, 0, 0, $x, $y, imagesx($small), imagesy($small), $w, $h);
            imagecopyresized($gd, $small, $x, $y, 0, 0, $w, $h, imagesx($small), imagesy($small));
            imagedestroy($small);
        }
        $blue = imagecolorallocate($gd, 0, 128, 255);
        $white = imagecolorallocate($gd, 255, 255, 255);
        imagesetthickness($gd, max(2, (int) round(imagesx($gd) / 500)));
        foreach ($recipe['marks'] ?? [] as $mark) {
            [$x, $y, $w, $h] = $this->pixels($gd, $mark);
            if ($mark['kind'] === 'boundary') {
                imagerectangle($gd, $x, $y, $x + $w - 1, $y + $h - 1, $blue);
            } else {
                imagefilledellipse($gd, $x + (int) ($w / 2), $y + (int) ($h / 2), max(12, (int) ($w / 2)), max(12, (int) ($h / 2)), $blue);
            }
            if ($mark['label'] ?? '') {
                imagettftext($gd, max(10, imagesx($gd) / 120), 0, $x + 4, $y + 18, $white, resource_path('fonts/Inter-Medium.ttf'), $mark['label']);
            }
        }
        if (isset($recipe['crop'])) {
            [$x, $y, $w, $h] = $this->pixels($gd, $recipe['crop']);
            $image->crop($w, $h, $x, $y);
        }
        if ($recipe['flip'] ?? false) {
            $image->flop();
        }
        if ($recipe['rotation'] ?? 0) {
            $image->rotate(-$recipe['rotation']);
        }
        if (isset($recipe['logo'])) {
            if (! $logoBytes) {
                throw new RuntimeException('Choose an available workspace logo.');
            }
            $size = @getimagesizefromstring($logoBytes);
            if (! $size || $size[0] * $size[1] > 4000000) {
                throw new RuntimeException('The logo exceeds the 4 megapixel limit.');
            }
            $logo = ImageManager::gd()->read($logoBytes)->orient()->scaleDown(width: max(1, (int) round($image->width() * $recipe['logo']['width'])), height: max(1, (int) round($image->height() * .25)));
            $x = min($image->width() - $logo->width(), (int) round($recipe['logo']['x'] * $image->width()));
            $y = min($image->height() - $logo->height(), (int) round($recipe['logo']['y'] * $image->height()));
            $image->place($logo, 'top-left', max(0, $x), max(0, $y), $recipe['logo']['opacity'] ?? 85);
        }
        if (trim($recipe['caption'] ?? '') !== '') {
            $gd = $image->core()->native();
            $text = $recipe['caption'];
            $height = max(26, (int) round(imagesy($gd) / 30));
            $strip = imagecreatetruecolor(imagesx($gd), $height);
            $fontSize = max(10, min($height * .5, imagesx($gd) / max(1, mb_strlen($text))));
            imagettftext($strip, $fontSize, 0, 8, (int) ($height * .7), imagecolorallocate($strip, 255, 255, 255), resource_path('fonts/Inter-Medium.ttf'), $text);
            imagecopymerge($gd, $strip, 0, imagesy($gd) - $height, 0, 0, imagesx($gd), $height, 75);
            imagedestroy($strip);
        }

        return (string) $image->toJpeg(96);
    }

    private function selectiveColor(array $rgb, array $bands): array
    {
        [$r, $g, $b] = array_map(fn ($v) => $v / 255, $rgb);
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;
        if ($delta < .00001) {
            return $rgb;
        }
        $l = ($max + $min) / 2;
        $s = $delta / (1 - abs(2 * $l - 1));
        $h = 60 * ($max === $r ? fmod(($g - $b) / $delta, 6) : ($max === $g ? ($b - $r) / $delta + 2 : ($r - $g) / $delta + 4));
        $h = fmod($h + 360, 360);
        $dh = $ds = $dl = 0;
        foreach (['red' => 0, 'orange' => 30, 'yellow' => 60, 'green' => 120, 'aqua' => 180, 'blue' => 240, 'purple' => 270, 'magenta' => 300] as $band => $center) {
            if (! isset($bands[$band])) {
                continue;
            }
            $distance = abs($h - $center);
            $weight = max(0, 1 - min($distance, 360 - $distance) / 45);
            $dh += ($bands[$band]['hue'] ?? 0) * $weight;
            $ds += ($bands[$band]['saturation'] ?? 0) / 100 * $weight;
            $dl += ($bands[$band]['lightness'] ?? 0) / 200 * $weight;
        }
        $h = fmod($h + $dh + 360, 360);
        $s = max(0, min(1, $s + $ds));
        $l = max(0, min(1, $l + $dl));
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        $channels = match ((int) floor($h / 60)) {
            0 => [$c, $x, 0], 1 => [$x, $c, 0], 2 => [0, $c, $x], 3 => [0, $x, $c], 4 => [$x, 0, $c], default => [$c, 0, $x]
        };

        return array_map(fn ($v) => (int) round(max(0, min(255, ($v + $m) * 255))), $channels);
    }

    private function pixels(\GdImage $image, array $area): array
    {
        $x = min(imagesx($image) - 1, (int) floor($area['x'] * imagesx($image)));
        $y = min(imagesy($image) - 1, (int) floor($area['y'] * imagesy($image)));

        return [$x, $y, max(1, min(imagesx($image) - $x, (int) round($area['width'] * imagesx($image)))), max(1, min(imagesy($image) - $y, (int) round($area['height'] * imagesy($image))))];
    }
}
