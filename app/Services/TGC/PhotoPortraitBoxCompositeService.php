<?php

namespace App\Services\TGC;

use Illuminate\Support\Facades\Storage;
use Log;

/**
 * Composites the user-uploaded photo portrait box images onto the
 * photo-portrait-box.png template, preserving the exact drag/zoom positions
 * the user set in the frontend (PhotoPortraitBoxPreview).
 *
 * The frontend box preview renders the same zone (top 43% / left 10% /
 * 36% x 42%) and exposes each photo's drag position as resolution-independent
 * fractions (xFraction / yFraction) relative to the photo's own slot frame,
 * plus a plain scale factor (zoom). The backend reproduces the exact same
 * transform math — fraction x the slot's own width/height — so a drag looks
 * identical at print resolution regardless of the breakpoint the user dragged
 * at. No preview-width normalization is needed.
 */
class PhotoPortraitBoxCompositeService
{
    private const TEMPLATE_PATH = 'tuckbox/photo-portrait-box.png';

    /**
     * @param array $boxImages Each entry: ['src' => base64, 'x_fraction' => float, 'y_fraction' => float, 'zoom' => float]
     * @param int   $width     Template width (must match photo-portrait-box.png)
     * @param int   $height    Template height
     * @return string|null PNG blob, or null when no images supplied
     */
    public function composite(array $boxImages, int $width = 2325, int $height = 1950, ?string $fallbackBlob = null): ?string
    {
        $templatePath = Storage::disk('local')->path(self::TEMPLATE_PATH);
        if (!file_exists($templatePath)) {
            Log::error('PhotoPortraitBoxCompositeService: template missing', ['path' => $templatePath]);
            return $fallbackBlob;
        }

        $canvas = imagecreatefrompng($templatePath);
        if (!$canvas) {
            return $fallbackBlob;
        }
        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);

        $clean = [];
        foreach ($boxImages as $entry) {
            $src = $entry['src'] ?? null;
            if (!is_string($src) || $src === '') {
                continue;
            }
            $blob = $this->decode($src);
            if ($blob === null) {
                continue;
            }
            $clean[] = [
                'blob'       => $blob,
                'x_fraction' => (float) ($entry['x_fraction'] ?? $entry['xFraction'] ?? 0),
                'y_fraction' => (float) ($entry['y_fraction'] ?? $entry['yFraction'] ?? 0),
                'zoom'       => (float) ($entry['zoom'] ?? 1),
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
     * Mirror of PhotoPortraitBoxPreview front-face zone:
     *   top: 43%, left: 10%, width: 36%, height: 42%
     * Slots use the same fan-out layout as the frontend getLayout().
     *
     * Each photo's drag is stored as xFraction / yFraction — a fraction of the
     * slot frame's OWN width/height (matching how the frontend CSS
     * translate(X%) resolves against the slot element). The frame is the fixed
     * slot rect (slotW x slotH); zoom scales the photo centered inside that
     * frame, and we clip to the frame first (mirroring overflow:hidden) then
     * apply the preset inset clip on top.
     */
    private function drawFrontFace($canvas, array $imgs, int $W, int $H): void
    {
        $zX = (int) ($W * 0.10);
        $zY = (int) ($H * 0.43);
        $zW = (int) ($W * 0.36);
        $zH = (int) ($H * 0.42);

        $total = count($imgs);
        $layout = $this->getLayout($total);

        // Sort by z so leaders paint last (on top)
        usort($layout, fn($a, $b) => ($a['z'] ?? 1) <=> ($b['z'] ?? 1));

        foreach ($layout as $slot) {
            if (!isset($imgs[$slot['i']])) {
                continue;
            }
            $entry = $imgs[$slot['i']];
            $srcImg = imagecreatefromstring($entry['blob']);
            if (!$srcImg) {
                continue;
            }
            imagealphablending($srcImg, true);
            imagesavealpha($srcImg, true);

            $slotW = (int) ($zW * ($slot['size'] ?? 0.55));
            $slotH = (int) ($slotW * 4 / 3);

            // Slot's un-dragged center / bottom (fraction-based fan position).
            $cx = (int) ($zX + $zW / 2 + ($slot['x'] / 100) * $zW);
            $baseLeft = (int) ($cx - $slotW / 2);
            $draggedLeft = (int) ($baseLeft + $entry['x_fraction'] * $slotW);

            $baseBottom = (int) ($zY + $zH + ($slot['y'] / 100) * $slotH);
            $draggedBottom = (int) ($baseBottom + $entry['y_fraction'] * $slotH);
            $draggedTop = (int) ($draggedBottom - $slotH);

            $srcW = imagesx($srcImg);
            $srcH = imagesy($srcImg);

            // Zoom scales the photo centered inside the fixed frame rect.
            $scaledW = (int) ($slotW * ($slot['scale'] ?? 1) * $entry['zoom']);
            $scaledH = (int) ($slotH * ($slot['scale'] ?? 1) * $entry['zoom']);
            $scaledX = (int) ($draggedLeft + ($slotW - $scaledW) / 2);
            $scaledY = (int) ($draggedTop + ($slotH - $scaledH) / 2);

            $scaled = imagecreatetruecolor($scaledW, $scaledH);
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            $trans = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
            imagefill($scaled, 0, 0, $trans);
            imagealphablending($scaled, true);

            // object-fit: cover, top-anchored (objectPosition: top center).
            imagecopyresampled($scaled, $srcImg, 0, 0, 0, 0, $scaledW, $scaledH, $srcW, $srcH);

            // Clip to the frame rect first (mirrors the slot's overflow:hidden).
            $frame = imagecreatetruecolor($slotW, $slotH);
            imagealphablending($frame, false);
            imagesavealpha($frame, true);
            $frameTrans = imagecolorallocatealpha($frame, 0, 0, 0, 127);
            imagefill($frame, 0, 0, $frameTrans);
            imagealphablending($frame, true);
            imagecopy($frame, $scaled, 0, 0, -$scaledX + $draggedLeft, -$scaledY + $draggedTop, $scaledW, $scaledH);
            imagedestroy($scaled);
            $scaled = $frame;
            $scaledW = $slotW;
            $scaledH = $slotH;

            // Then apply the preset inset clip on top.
            if (!empty($slot['clip'])) {
                $clipped = $this->applyInsetClip($scaled, $scaledW, $scaledH, $slot['clip']);
                imagedestroy($scaled);
                $scaled = $clipped;
                $scaledW = imagesx($scaled);
                $scaledH = imagesy($scaled);
            }

            imagecopy($canvas, $scaled, $draggedLeft, $draggedTop, 0, 0, $scaledW, $scaledH);
            imagedestroy($scaled);
            imagedestroy($srcImg);
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
        $parts  = explode(' ', $clip);
        $top    = (int) (floatval($parts[0] ?? 0) / 100 * $h);
        $right  = (int) (floatval($parts[1] ?? 0) / 100 * $w);
        $bottom = (int) (floatval($parts[2] ?? 0) / 100 * $h);
        $left   = (int) (floatval($parts[3] ?? 0) / 100 * $w);

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
}
