<?php

namespace App\Services\TGC;

use Illuminate\Support\Facades\Storage;
use Log;

class TuckBoxCompositeService
{
    private const W = 2325;
    private const H = 1950;
    private const TEMPLATE_PATH = 'tuckbox/boxtemplate_2325x1950.png';

    public function composite(array $characterBlobs): string
    {

        $W = self::W;
        $H = self::H;

        $templatePath = Storage::disk('local')->path(self::TEMPLATE_PATH);
        $canvas = imagecreatefrompng($templatePath);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);

        $total = count($characterBlobs);
        if ($total === 0) {
            return $this->toPng($canvas);
        }

        $imgs = array_map(function ($blob) {
            $img = imagecreatefromstring($blob);
            imagealphablending($img, true);
            imagesavealpha($img, true);
            return $img;
        }, $characterBlobs);

        $this->drawZone1($canvas, $imgs, $W, $H);
        $this->drawZone2($canvas, $imgs, $W, $H, $total);
        $this->drawZone3($canvas, $imgs, $W, $H, $total);

        foreach ($imgs as $img) {
            imagedestroy($img);
        }

        return $this->toPng($canvas);
    }

    private function drawZone1($canvas, array $imgs, int $W, int $H): void
    {
        $total = count($imgs);

        // CSS: top:9.5%, left:10%, width:36%, height:11%, rotate(180deg)
        $zX = (int)($W * 0.08);
        $zY = (int)($H * 0.095);
        $zW = (int)($W * 0.36);
        $zH = (int)($H * 0.11);

        $bustSize = (int)min($zW * 0.22, $zW * 0.90 / $total);
        $overlap  = (int)($bustSize * 0.08);

        $temp = imagecreatetruecolor($zW, $zH);
        imagealphablending($temp, false);
        imagesavealpha($temp, true);
        $transparent = imagecolorallocatealpha($temp, 0, 0, 0, 127);
        imagefill($temp, 0, 0, $transparent);
        imagealphablending($temp, true);

        foreach ($imgs as $idx => $img) {
            $bx = $idx * ($bustSize - $overlap);
            $by = $zH - $bustSize;

            $circle = imagecreatetruecolor($bustSize, $bustSize);
            imagealphablending($circle, false);
            imagesavealpha($circle, true);
            $trans = imagecolorallocatealpha($circle, 0, 0, 0, 127);
            imagefill($circle, 0, 0, $trans);
            imagealphablending($circle, true);

            $srcW  = imagesx($img);
            $srcH  = imagesy($img);
            $cropH = (int)($srcH * 0.45);

            imagecopyresampled($circle, $img, 0, 0, 0, 0, $bustSize, $bustSize, $srcW, $cropH);

            $masked = $this->applyCircularMask($circle, $bustSize);
            imagecopy($temp, $masked, $bx, $by, 0, 0, $bustSize, $bustSize);

            imagedestroy($circle);
            imagedestroy($masked);
        }

        $rotated = imagerotate($temp, 180, imagecolorallocatealpha($temp, 0, 0, 0, 127));
        imagesavealpha($rotated, true);
        imagecopy($canvas, $rotated, $zX, $zY, 0, 0, $zW, $zH);

        imagedestroy($temp);
        imagedestroy($rotated);
    }

    private function drawZone2($canvas, array $imgs, int $W, int $H, int $total): void
{
    $zX = (int)($W * 0.10);
    $zY = (int)($H * 0.43);
    $zW = (int)($W * 0.36);
    $zH = (int)($H * 0.42);

    $layout = $this->getZone2Layout($total);
    usort($layout, fn($a, $b) => ($a['z'] ?? 1) <=> ($b['z'] ?? 1));

    foreach ($layout as $slot) {
        $slotW = (int)($zW * $slot['size']);
        $slotH = (int)($slotW * 4 / 3);

        $cx = (int)($zX + $zW / 2 + ($slot['x'] / 100) * $zW);

        $img  = $imgs[$slot['i']];
        $srcW = imagesx($img);
        $srcH = imagesy($img);

        $scaledW = (int)($slotW * $slot['scale']);
        $scaledH = (int)($slotH * $slot['scale']);
        $scaledX = (int)($cx - $scaledW / 2);

        // ✅ If slot has feet_y, pin feet to that canvas pixel directly
        // Otherwise fall back to original cy formula
        if (isset($slot['feet_y'])) {
            $feetY   = (int)($H * $slot['feet_y'] / 100);
            $scaledY = $feetY - $scaledH;
        } else {
            $cy      = (int)($zY + $zH + ($slot['y'] / 100) * $zH);
            $scaledY = (int)($cy - $scaledH);
        }

        $scaled = imagecreatetruecolor($scaledW, $scaledH);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        $trans = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
        imagefill($scaled, 0, 0, $trans);
        imagealphablending($scaled, true);
        imagecopyresampled($scaled, $img, 0, 0, 0, 0, $scaledW, $scaledH, $srcW, $srcH);

        if (!empty($slot['clip'])) {
            $parts    = explode(' ', $slot['clip']);
            $clipLeft = (int)(floatval($parts[3]) / 100 * $scaledW);
            $clipTop  = (int)(floatval($parts[0]) / 100 * $scaledH);
            $clipped  = $this->applyInsetClip($scaled, $scaledW, $scaledH, $slot['clip']);
            imagedestroy($scaled);
            $scaled   = $clipped;
            $scaledX += $clipLeft;
            $scaledY += $clipTop;
        }

        imagecopy($canvas, $scaled, $scaledX, $scaledY, 0, 0, imagesx($scaled), imagesy($scaled));
        imagedestroy($scaled);
    }
}

    private function drawZone3($canvas, array $imgs, int $W, int $H, int $total): void
    {
        // CSS: top:28%, left:51%, width:8%, height:45%
        $zX = (int)($W * 0.51);
        $zY = (int)($H * 0.28);
        $zW = (int)($W * 0.08);
        $zH = (int)($H * 0.45);

        $layout   = $this->getZone3Layout($total);
        $currentY = $zY;

        foreach ($layout as $key => $slot) {
            $isLeader = $slot['isLeader'];

            // CSS: width 85/70% of container, aspectRatio 1/1
            $itemW = (int)($zW * ($isLeader ? 0.85 : 0.70));
            $itemH = $itemW;

            // CSS: marginTop -12% (resolves against container width in CSS)
            $marginTop = $key === 0 ? 0 : (int)($zW * -0.12);
            $currentY += $marginTop;

            // Build upright character temp canvas
            $temp = imagecreatetruecolor($itemW, $itemH);
            imagealphablending($temp, false);
            imagesavealpha($temp, true);
            $trans = imagecolorallocatealpha($temp, 0, 0, 0, 127);
            imagefill($temp, 0, 0, $trans);
            imagealphablending($temp, true);

            $img  = $imgs[$slot['i']];
            $srcW = imagesx($img);
            $srcH = imagesy($img);

            // CSS img: width:100%, height:250%, top:0%, left:-10%
            $drawW    = $itemW;
            $drawH    = (int)($itemH * 2.50);
            $drawDstX = (int)(-$itemW * 0.10);

            imagecopyresampled($temp, $img, $drawDstX, 0, 0, 0, $drawW, $drawH, $srcW, $srcH);

            // CSS: borderRadius 40% for non-leaders
            if (!$isLeader) {
                $temp = $this->applyRoundedMask($temp, $itemW, $itemH, 0.40);
            }

            // CSS: rotate(-90deg) — in GD counter-clockwise 90 = CSS clockwise -90
            $bgColor = imagecolorallocatealpha($temp, 0, 0, 0, 127);
            $rotated = imagerotate($temp, 90, $bgColor);
            imagesavealpha($rotated, true);

            $rotW = imagesx($rotated); // = itemH after rotation
            $rotH = imagesy($rotated); // = itemW after rotation

            // CSS flex items-center: element centered horizontally in zone
            $elemCenterX = $zX + $zW / 2;
            $elemCenterY = $currentY + $itemH / 2;

            // CSS transform: translateX(-80/-90%) translateY(0/-40%)
            // Percentages relative to element's OWN dimensions (pre-rotation)
            $tx = ($isLeader ? -0.80 : -0.90) * $itemW;
            $ty = ($isLeader ?  0.00 : -0.40) * $itemH;

            // Apply translation to center point
            $finalCenterX = $elemCenterX + $tx;
            $finalCenterY = $elemCenterY + $ty;

            // Top-left corner for imagecopy
            $destX = (int)($finalCenterX - $rotW / 2);
            $destY = (int)($finalCenterY - $rotH / 2);

            imagecopy($canvas, $rotated, $destX, $destY, 0, 0, $rotW, $rotH);

            imagedestroy($temp);
            imagedestroy($rotated);

            $currentY += $itemH;
        }
    }

    private function getZone2Layout(int $total): array
    {
        if ($total === 1) return [
            ['i' => 0, 'x' => 0, 'y' => 85, 'scale' => 1, 'size' => 0.55, 'z' => 3],
        ];
        if ($total === 2) return [
            ['i' => 1, 'x' => -20, 'y' => 72, 'scale' => 0.72, 'size' => 0.38, 'z' => 1],
            ['i' => 0, 'x' => 0,   'y' => 85, 'scale' => 1,    'size' => 0.55, 'z' => 3],
        ];
        if ($total === 3) return [
            ['i' => 1, 'x' => -22, 'y' => 72, 'scale' => 0.72, 'size' => 0.38, 'z' => 1],
            ['i' => 2, 'x' =>  22, 'y' => 72, 'scale' => 0.72, 'size' => 0.38, 'z' => 1],
            ['i' => 0, 'x' => 0,   'y' => 85, 'scale' => 1,    'size' => 0.55, 'z' => 3],
        ];
        if ($total === 4) return [
            ['i' => 0, 'x' => -21, 'y' => -15, 'scale' => 0.65, 'size' => 0.95, 'z' => 1],
            ['i' => 3, 'x' =>  21, 'y' => -15, 'scale' => 0.65, 'size' => 0.95, 'z' => 1],
            ['i' => 1, 'x' => -21, 'y' =>  10, 'scale' => 0.78, 'size' => 0.80, 'z' => 2, 'clip' => '0% 27% 55% 28%'],
            ['i' => 2, 'x' =>  21, 'y' =>  10, 'scale' => 0.78, 'size' => 0.80, 'z' => 2, 'clip' => '0% 27% 55% 25%'],
            ['i' => 0, 'x' => 0,   'y' => 30,  'scale' => 0.75, 'size' => 0.99, 'z' => 3, 'clip' => '0% 25% 0% 25%'],
        ];

        return [
            ['i' => 3, 'x' => -17, 'y' => -2,  'scale' => 0.80, 'size' => 0.99, 'z' => 1, 'clip' => '0% 25% 10% 24%'],
            ['i' => 4, 'x' =>  17, 'y' => -2,  'scale' => 0.80, 'size' => 0.99, 'z' => 1, 'clip' => '0% 25% 10% 24%'],
            ['i' => 1, 'x' => -22, 'y' =>  10, 'scale' => 0.78, 'size' => 0.80, 'z' => 2, 'clip' => '0% 27% 55% 28%'],
            ['i' => 2, 'x' =>  22, 'y' =>  10, 'scale' => 0.78, 'size' => 0.80, 'z' => 2, 'clip' => '0% 27% 55% 25%'],
            ['i' => 0, 'x' => 0, 'y' => 45, 'scale' => 1.00, 'size' => 0.99, 'z' => 3, 'clip' => '0% 25% 0% 25%'],
        ];
    }

    private function getZone3Layout(int $total): array
    {
        if ($total === 1) return [['i' => 0, 'isLeader' => true]];
        if ($total === 2) return [
            ['i' => 1, 'isLeader' => false],
            ['i' => 0, 'isLeader' => false],
            ['i' => 0, 'isLeader' => true],
        ];
        if ($total === 3) return [
            ['i' => 2, 'isLeader' => false],
            ['i' => 1, 'isLeader' => false],
            ['i' => 0, 'isLeader' => false],
            ['i' => 0, 'isLeader' => true],
        ];
        if ($total === 4) return [
            ['i' => 3, 'isLeader' => false],
            ['i' => 2, 'isLeader' => false],
            ['i' => 1, 'isLeader' => false],
            ['i' => 0, 'isLeader' => false],
            ['i' => 0, 'isLeader' => true],
        ];
        return [
            ['i' => 4, 'isLeader' => false],
            ['i' => 3, 'isLeader' => false],
            ['i' => 2, 'isLeader' => false],
            ['i' => 1, 'isLeader' => false],
            ['i' => 0, 'isLeader' => true],
        ];
    }

    private function applyCircularMask($img, int $size)
    {
        $masked = imagecreatetruecolor($size, $size);
        imagealphablending($masked, false);
        imagesavealpha($masked, true);
        $trans = imagecolorallocatealpha($masked, 0, 0, 0, 127);
        imagefill($masked, 0, 0, $trans);
        imagealphablending($masked, true);

        $cx = $size / 2;
        $cy = $size / 2;
        $r  = $size / 2;

        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {
                if ((($x - $cx) ** 2 + ($y - $cy) ** 2) <= $r ** 2) {
                    $color = imagecolorat($img, $x, $y);
                    imagesetpixel($masked, $x, $y, $color);
                }
            }
        }
        return $masked;
    }

    private function applyRoundedMask($img, int $w, int $h, float $radiusRatio)
    {
        $masked = imagecreatetruecolor($w, $h);
        imagealphablending($masked, false);
        imagesavealpha($masked, true);
        $trans = imagecolorallocatealpha($masked, 0, 0, 0, 127);
        imagefill($masked, 0, 0, $trans);
        imagealphablending($masked, true);

        $rx = (int)($w * $radiusRatio);
        $ry = (int)($h * $radiusRatio);

        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                $inCorner = false;
                if ($x < $rx && $y < $ry && (($x - $rx) ** 2 / $rx ** 2 + ($y - $ry) ** 2 / $ry ** 2) > 1) $inCorner = true;
                if ($x >= $w - $rx && $y < $ry && (($x - ($w - $rx)) ** 2 / $rx ** 2 + ($y - $ry) ** 2 / $ry ** 2) > 1) $inCorner = true;
                if ($x < $rx && $y >= $h - $ry && (($x - $rx) ** 2 / $rx ** 2 + ($y - ($h - $ry)) ** 2 / $ry ** 2) > 1) $inCorner = true;
                if ($x >= $w - $rx && $y >= $h - $ry && (($x - ($w - $rx)) ** 2 / $rx ** 2 + ($y - ($h - $ry)) ** 2 / $ry ** 2) > 1) $inCorner = true;

                if (!$inCorner) {
                    $color = imagecolorat($img, $x, $y);
                    imagesetpixel($masked, $x, $y, $color);
                }
            }
        }
        return $masked;
    }

    private function applyInsetClip($img, int $w, int $h, string $clip)
    {
        $parts  = explode(' ', $clip);
        $top    = (int)(floatval($parts[0]) / 100 * $h);
        $right  = (int)(floatval($parts[1]) / 100 * $w);
        $bottom = (int)(floatval($parts[2]) / 100 * $h);
        $left   = (int)(floatval($parts[3]) / 100 * $w);

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

    private function toPng($img): string
    {
        ob_start();
        imagepng($img);
        $blob = ob_get_clean();
        imagedestroy($img);
        return $blob;
    }
}