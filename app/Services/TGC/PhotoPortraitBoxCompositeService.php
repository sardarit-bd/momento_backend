<?php

namespace App\Services\TGC;

use Illuminate\Support\Facades\Storage;
use Log;

/**
 * Composites the user-uploaded photo portrait box images onto the
 * photo-portrait-box.png template, using the ALREADY-RESOLVED geometry the
 * browser computed for each photo.
 */
class PhotoPortraitBoxCompositeService
{
    private const TEMPLATE_PATH = 'tuckbox/photo-portrait-box.png';

    public function composite(array $boxImages, int $width = 2325, int $height = 1950, ?string $fallbackBlob = null): ?string
    {
        $templatePath = Storage::disk('local')->path(self::TEMPLATE_PATH);
        if (! file_exists($templatePath)) {
            Log::error('PhotoPortraitBoxCompositeService: template missing', ['path' => $templatePath]);

            return $fallbackBlob;
        }

        $canvas = imagecreatefrompng($templatePath);
        if (! $canvas) {
            return $fallbackBlob;
        }
        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);

        $clean = [];
        foreach ($boxImages as $entry) {
            $src = $entry['src'] ?? null;
            if (! is_string($src) || $src === '') {
                continue;
            }
            $blob = $this->decode($src);
            if ($blob === null) {
                continue;
            }
            $clean[] = [
                'blob' => $blob,
                'frame' => $entry['frame'] ?? null,
                'image' => $entry['image'] ?? null,
            ];
        }

        if (empty($clean)) {
            imagedestroy($canvas);

            return $fallbackBlob;
        }

        $this->drawFrontFace($canvas, $clean, $width, $height);

        ob_start();
        imagepng($canvas);
        $blob = ob_get_clean();
        imagedestroy($canvas);

        return $blob;
    }

    /**
     * Convert a {leftFrac, topFrac, widthFrac, heightFrac} rect (fractions of
     * the outer box container, i.e. of the template) into integer template
     * pixels. Returns [left, top, width, height].
     */
    private function fractionsToPixels(array $rect, int $W, int $H): array
    {
        $leftFrac = (float) ($rect['leftFrac'] ?? 0);
        $topFrac = (float) ($rect['topFrac'] ?? 0);
        $widthFrac = (float) ($rect['widthFrac'] ?? 0);
        $heightFrac = (float) ($rect['heightFrac'] ?? 0);

        return [
            (int) round($leftFrac * $W),
            (int) round($topFrac * $H),
            max(1, (int) round($widthFrac * $W)),
            max(1, (int) round($heightFrac * $H)),
        ];
    }

    /**
     * Draw each photo from its resolved frame/image rects. Array order is the
     * z-order (earlier = painted first = behind). Every photo is clipped to its
     * frame rect unconditionally; decorative getLayout() insets are a second,
     * additional crop applied on top when present.
     */
    private function drawFrontFace($canvas, array $imgs, int $W, int $H): void
    {

        $insetByIndex = [];
        $layout = $this->getLayout(count($imgs));
        foreach ($layout as $slot) {
            if (! empty($slot['clip'])) {
                $insetByIndex[$slot['i'] ?? 0] = $slot['clip'];
            }
        }

        foreach ($imgs as $index => $entry) {
            $frameRect = $entry['frame'];
            $imageRect = $entry['image'] ?? $entry['frame'];
            if (! is_array($frameRect) || ! is_array($imageRect)) {
                continue;
            }

            [$fLeft, $fTop, $fW, $fH] = $this->fractionsToPixels($frameRect, $W, $H);
            [$iLeft, $iTop, $iW, $iH] = $this->fractionsToPixels($imageRect, $W, $H);

            $srcImg = imagecreatefromstring($entry['blob']);
            if (! $srcImg) {
                continue;
            }
            imagealphablending($srcImg, true);
            imagesavealpha($srcImg, true);

            $srcW = imagesx($srcImg);
            $srcH = imagesy($srcImg);

            $scaled = imagecreatetruecolor($iW, $iH);
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            $trans = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
            imagefill($scaled, 0, 0, $trans);
            imagealphablending($scaled, true);
            imagecopyresampled($scaled, $srcImg, 0, 0, 0, 0, $iW, $iH, $srcW, $srcH);
            imagedestroy($srcImg);

            $frame = imagecreatetruecolor($fW, $fH);
            imagealphablending($frame, false);
            imagesavealpha($frame, true);
            $frameTrans = imagecolorallocatealpha($frame, 0, 0, 0, 127);
            imagefill($frame, 0, 0, $frameTrans);
            imagealphablending($frame, true);

            $dstX = $iLeft - $fLeft;
            $dstY = $iTop - $fTop;
            imagecopy($frame, $scaled, $dstX, $dstY, 0, 0, $iW, $iH);
            imagedestroy($scaled);
            $scaled = $frame;
            $scaledW = $fW;
            $scaledH = $fH;

            if (! empty($insetByIndex[$index])) {
                $clipped = $this->applyInsetClip($scaled, $scaledW, $scaledH, $insetByIndex[$index]);
                imagedestroy($scaled);
                $scaled = $clipped;
                $scaledW = imagesx($scaled);
                $scaledH = imagesy($scaled);
            }

            imagecopy($canvas, $scaled, $fLeft, $fTop, 0, 0, $scaledW, $scaledH);
            imagedestroy($scaled);
        }
    }

    private function getLayout(int $total): array
    {
        if ($total === 1) {
            return [['i' => 0, 'x' => 0, 'y' => 0, 'z' => 3, 'size' => 0.55]];
        }
        if ($total === 2) {
            return [
                ['i' => 1, 'x' => -20, 'y' => -18, 'z' => 1, 'size' => 0.38],
                ['i' => 0, 'x' => 0,   'y' => 0,  'z' => 3, 'size' => 0.55],
            ];
        }
        if ($total === 3) {
            return [
                ['i' => 1, 'x' => -22, 'y' => -18, 'z' => 1, 'size' => 0.38],
                ['i' => 2, 'x' => 22,  'y' => -18, 'z' => 1, 'size' => 0.38],
                ['i' => 0, 'x' => 0,   'y' => 0,  'z' => 3, 'size' => 0.55],
            ];
        }
        if ($total === 4) {
            return [
                ['i' => 0, 'x' => -22, 'y' => -15, 'z' => 1, 'size' => 0.95, 'clip' => '0% 25% 10% 23%'],
                ['i' => 3, 'x' => 22,  'y' => -15, 'z' => 1, 'size' => 0.95, 'clip' => '0% 23% 10% 25%'],
                ['i' => 1, 'x' => -26, 'y' => 10,  'z' => 2, 'size' => 0.80, 'clip' => '0% 27% 55% 28%'],
                ['i' => 2, 'x' => 26,  'y' => 10,  'z' => 2, 'size' => 0.80, 'clip' => '0% 27% 55% 25%'],
                ['i' => 0, 'x' => 0,   'y' => 32,  'z' => 3, 'size' => 0.99, 'clip' => '0% 25% 49.3% 25%'],
            ];
        }

        return [
            ['i' => 3, 'x' => -17, 'y' => -2,  'z' => 1, 'size' => 0.99, 'clip' => '0% 25% 10% 24%'],
            ['i' => 4, 'x' => 17,  'y' => -2,  'z' => 1, 'size' => 0.99, 'clip' => '0% 25% 10% 24%'],
            ['i' => 1, 'x' => -26, 'y' => 10,  'z' => 2, 'size' => 0.80, 'clip' => '0% 27% 55% 28%'],
            ['i' => 2, 'x' => 26,  'y' => 10,  'z' => 2, 'size' => 0.80, 'clip' => '0% 27% 55% 25%'],
            ['i' => 0, 'x' => 0,   'y' => 32,  'z' => 3, 'size' => 0.99, 'clip' => '0% 25% 49.3% 25%'],
        ];
    }

    private function decode(string $payload): ?string
    {
        if (str_starts_with($payload, 'data:')) {
            $payload = explode(',', $payload, 2)[1] ?? '';
        }
        $decoded = base64_decode(str_replace(' ', '+', $payload), true);

        return ($decoded !== false && $decoded !== '') ? $decoded : null;
    }

    private function applyInsetClip($img, int $w, int $h, string $clip)
    {
        $parts = explode(' ', $clip);
        $top = (int) (floatval($parts[0] ?? 0) / 100 * $h);
        $right = (int) (floatval($parts[1] ?? 0) / 100 * $w);
        $bottom = (int) (floatval($parts[2] ?? 0) / 100 * $h);
        $left = (int) (floatval($parts[3] ?? 0) / 100 * $w);

        $newW = max(1, $w - $left - $right);
        $newH = max(1, $h - $top - $bottom);

        $cropped = imagecreatetruecolor($newW, $newH);
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);
        $trans = imagecolorallocatealpha($cropped, 0, 0, 0, 127);
        imagefill($cropped, 0, 0, $trans);
        imagealphablending($cropped, true);
        imagecopy($cropped, $img, 0, 0, $left, $top, $newW, $newH);

        return $cropped;
    }

    /**
     * Debug helper: returns the actual pixel rects GD will draw for each entry,
     * recomputed from the resolved fractions, so a saved payload can be diffed
     * against the frontend-captured rects. Delta should be ~0px modulo rounding.
     *
     * @return array<int, array{frame:array,image:array}>
     */
    public function debugRects(array $boxImages, int $W = 2325, int $H = 1950): array
    {
        $out = [];
        foreach ($boxImages as $index => $entry) {
            $frame = $entry['frame'] ?? null;
            $image = $entry['image'] ?? $entry['frame'] ?? null;
            if (! is_array($frame) || ! is_array($image)) {
                continue;
            }
            [$fL, $fT, $fW, $fH] = $this->fractionsToPixels($frame, $W, $H);
            [$iL, $iT, $iW, $iH] = $this->fractionsToPixels($image, $W, $H);
            $out[$index] = [
                'frame' => ['left' => $fL, 'top' => $fT, 'width' => $fW, 'height' => $fH],
                'image' => ['left' => $iL, 'top' => $iT, 'width' => $iW, 'height' => $iH],
            ];
        }

        return $out;
    }
}
