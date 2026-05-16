<?php

namespace App\Services\TGC;

use Illuminate\Support\Facades\Storage;

class TuckBoxCompositeService
{
    private const W = 2325;
    private const H = 1950;
    private const TEMPLATE_PATH = 'tuckbox/boxtemplate_2325x1950.png';

    // Zone 1 — Top flap
    private const Z1 = ['top' => 0.095, 'left' => 0.10, 'w' => 0.36, 'h' => 0.11];

    // Zone 2 — Front face
    private const Z2 = ['top' => 0.43, 'left' => 0.10, 'w' => 0.36, 'h' => 0.42];

    // Zone 3 — Right side strip
    private const Z3 = ['top' => 0.10, 'left' => 0.00, 'w' => 0.28, 'h' => 0.25];

    public function composite(array $characterBlobs): string
    {
        $W = self::W;
        $H = self::H;

        // Load template
        $templatePath = Storage::disk('local')->path(self::TEMPLATE_PATH);
        $canvas = imagecreatefrompng($templatePath);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);

        $total = count($characterBlobs);
        if ($total === 0) {
            return $this->toPng($canvas);
        }

        // Load character images
        $imgs = array_map(function ($blob) {
            $img = imagecreatefromstring($blob);
            imagealphablending($img, true);
            imagesavealpha($img, true);
            return $img;
        }, $characterBlobs);

        // ── Zone 1 — Top flap (rotated 180deg, horizontal bust row) ──
        $this->drawZone1($canvas, $imgs, $W, $H);

        // ── Zone 2 — Front face pyramid ──────────────────────────────
        $this->drawZone2($canvas, $imgs, $W, $H, $total);

        // ── Zone 3 — Right side strip ─────────────────────────────────
        $this->drawZone3($canvas, $imgs, $W, $H, $total);

        // Cleanup
        foreach ($imgs as $img) {
            imagedestroy($img);
        }

        return $this->toPng($canvas);
    }

    private function drawZone1($canvas, array $imgs, int $W, int $H): void
    {
        $total = count($imgs);
        $z = self::Z1;

        $zX = (int)($W * $z['left']);
        $zY = (int)($H * $z['top']);
        $zW = (int)($W * $z['w']);
        $zH = (int)($H * $z['h']);

        $bustSize = (int)min($zW * 0.22, $zW * 0.90 / $total);
        $overlap  = (int)($bustSize * 0.08);

        $totalBustWidth = $bustSize * $total - $overlap * ($total - 1);
        $startX = $zX + (int)(($zW - $totalBustWidth) / 2);
        $bustY  = $zY + $zH - $bustSize;

        // Zone 1 is rotated 180deg — draw into temp canvas then rotate
        $tempW = $zW;
        $tempH = $zH;
        $temp  = imagecreatetruecolor($tempW, $tempH);
        imagealphablending($temp, false);
        imagesavealpha($temp, true);
        $transparent = imagecolorallocatealpha($temp, 0, 0, 0, 127);
        imagefill($temp, 0, 0, $transparent);
        imagealphablending($temp, true);

        foreach ($imgs as $idx => $img) {
            $bx = $idx * ($bustSize - $overlap);
            $by = $zH - $bustSize;

            // Circular clip via temp layer
            $circle = imagecreatetruecolor($bustSize, $bustSize);
            imagealphablending($circle, false);
            imagesavealpha($circle, true);
            $trans = imagecolorallocatealpha($circle, 0, 0, 0, 127);
            imagefill($circle, 0, 0, $trans);
            imagealphablending($circle, true);

            // Scale character image to bust size, show top portion (head)
            $srcW = imagesx($img);
            $srcH = imagesy($img);
            $cropH = (int)($srcH * 0.45); // top 45% = head area

            imagecopyresampled($circle, $img, 0, 0, 0, 0, $bustSize, $bustSize, $srcW, $cropH);

            // Apply circular mask
            $masked = $this->applyCircularMask($circle, $bustSize);
            imagecopy($temp, $masked, $bx, $by, 0, 0, $bustSize, $bustSize);

            imagedestroy($circle);
            imagedestroy($masked);
        }

        // Rotate 180deg
        $rotated = imagerotate($temp, 180, imagecolorallocatealpha($temp, 0, 0, 0, 127));
        imagesavealpha($rotated, true);

        imagecopy($canvas, $rotated, $zX, $zY, 0, 0, $zW, $zH);

        imagedestroy($temp);
        imagedestroy($rotated);
    }

    private function drawZone2($canvas, array $imgs, int $W, int $H, int $total): void
    {
        $z  = self::Z2;
        $zX = (int)($W * $z['left']);
        $zY = (int)($H * $z['top']);
        $zW = (int)($W * $z['w']);
        $zH = (int)($H * $z['h']);

        $layout = $this->getZone2Layout($total);

        // Sort by z so lower z drawn first
        usort($layout, fn($a, $b) => ($a['z'] ?? 1) <=> ($b['z'] ?? 1));

        foreach ($layout as $slot) {
            $slotW = (int)($zW * $slot['size']);
            $slotH = (int)($slotW * 4 / 3);

            // translateX calc(-50% + x%) translateY(y%) from bottom center
            $cx = (int)($zX + $zW / 2 + ($slot['x'] / 100) * $zW);
            $cy = (int)($zY + $zH + ($slot['y'] / 100) * $zH);

            $img    = $imgs[$slot['i']];
            $srcW   = imagesx($img);
            $srcH   = imagesy($img);

            // Scale with transformOrigin bottom center
            $destX = (int)($cx - $slotW / 2);
            $destY = (int)($cy - $slotH);

            // Apply scale
            $scaledW = (int)($slotW * $slot['scale']);
            $scaledH = (int)($slotH * $slot['scale']);
            $scaledX = (int)($cx - $scaledW / 2);
            $scaledY = (int)($cy - $scaledH);

            // Create scaled temp
            $scaled = imagecreatetruecolor($scaledW, $scaledH);
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            $trans = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
            imagefill($scaled, 0, 0, $trans);
            imagealphablending($scaled, true); 
            imagecopyresampled($scaled, $img, 0, 0, 0, 0, $scaledW, $scaledH, $srcW, $srcH);

            if (!empty($slot['clip'])) {
                $parts  = explode(' ', $slot['clip']);
                $clipLeft = (int)(floatval($parts[3]) / 100 * $scaledW);
                $clipTop  = (int)(floatval($parts[0]) / 100 * $scaledH);
                
                $clipped = $this->applyInsetClip($scaled, $scaledW, $scaledH, $slot['clip']);
                imagedestroy($scaled);
                $scaled = $clipped;
                
                // Adjust position to account for clipping
                $scaledX += $clipLeft;
                $scaledY += $clipTop;
            }

            imagecopy($canvas, $scaled, $scaledX, $scaledY, 0, 0, imagesx($scaled), imagesy($scaled));
            imagedestroy($scaled);
        }
    }

    // private function drawZone3($canvas, array $imgs, int $W, int $H, int $total): void
    // {
    //     $z  = self::Z3;
    //     $zX = (int)($W * $z['left']);
    //     $zY = (int)($H * $z['top']);
    //     $zW = (int)($W * $z['w']);
    //     $zH = (int)($H * $z['h']);

    //     $layout    = $this->getZone3Layout($total);
    //     $currentY  = $zY;

    //     foreach ($layout as $key => $slot) {
    //         $itemW     = (int)($zW * ($slot['isLeader'] ? 0.85 : 0.70));
    //         $itemH     = $itemW;
    //         $marginTop = $key === 0 ? 0 : (int)($itemH * (-0.12));
    //         $currentY += $marginTop;

    //         $xShift = $slot['isLeader'] ? -0.80 : -0.90;
    //         $yShift = $slot['isLeader'] ? 0.0  : -0.40;

    //         $itemX = (int)($zX + $zW / 2 + $xShift * $itemW);
    //         $itemY = (int)($currentY + $yShift * $itemH);

    //         $img  = $imgs[$slot['i']];
    //         $srcW = imagesx($img);
    //         $srcH = imagesy($img);

    //         // Draw head: height=250%, top=0, left=-10%
    //         $drawW = (int)($itemW * 1.10);
    //         $drawH = (int)($itemH * 2.50);
    //         $drawX = (int)($itemX - $itemW * 0.10);
    //         $drawY = $itemY;

    //         // Create temp for this slot
    //         $temp = imagecreatetruecolor($itemW, $itemH);
    //         imagealphablending($temp, false);
    //         imagesavealpha($temp, true);
    //         $trans = imagecolorallocatealpha($temp, 0, 0, 0, 127);
    //         imagefill($temp, 0, 0, $trans);
    //         imagealphablending($temp, true);

    //         imagecopyresampled($temp, $img,
    //             (int)(-$itemW * 0.10), 0,
    //             0, 0,
    //             $drawW, $drawH,
    //             $srcW, $srcH
    //         );

    //         // Apply rounded clip for non-leaders
    //         if (!$slot['isLeader']) {
    //             $temp = $this->applyRoundedMask($temp, $itemW, $itemH, 0.40);
    //         }

    //         // Rotate -90deg
    //         $rotated = imagerotate($temp, 90, imagecolorallocatealpha($temp, 0, 0, 0, 127));
    //         imagesavealpha($rotated, true);

    //         imagecopy($canvas, $rotated, $itemX, $itemY, 0, 0, $itemW, $itemH);

    //         imagedestroy($temp);
    //         imagedestroy($rotated);

    //         $currentY += $itemH;
    //     }
    // }


    private function drawZone3($canvas, array $imgs, int $W, int $H, int $total): void
    {
        $z  = self::Z3;
        $zX = (int)($W * $z['left']);
        $zY = (int)($H * $z['top']);
        $zW = (int)($W * $z['w']);
        $zH = (int)($H * $z['h']);

        $layout   = $this->getZone3Layout($total);
        $currentY = $zY;

        foreach ($layout as $key => $slot) {
            $isLeader = $slot['isLeader'];

            $itemW     = (int)($zW * ($isLeader ? 0.85 : 0.70));
            $itemH     = $itemW;
            $marginTop = $key === 0 ? 0 : (int)($itemH * -0.12);
            $currentY += $marginTop;

            // ── STEP 1: Build the temp canvas (character drawn upright) ──
            $temp = imagecreatetruecolor($itemW, $itemH);
            imagealphablending($temp, false);
            imagesavealpha($temp, true);
            $trans = imagecolorallocatealpha($temp, 0, 0, 0, 127);
            imagefill($temp, 0, 0, $trans);
            imagealphablending($temp, true);

            $img  = $imgs[$slot['i']];
            $srcW = imagesx($img);
            $srcH = imagesy($img);

            // Draw head: height=250%, horizontal offset=-10%
            $drawW   = (int)($itemW * 1.10);
            $drawH   = (int)($itemH * 2.50);
            $drawDstX = (int)(-$itemW * 0.10);  // pre-rotation X offset

            imagecopyresampled($temp, $img,
                $drawDstX, 0,
                0, 0,
                $drawW, $drawH,
                $srcW, $srcH
            );

            // Apply rounded clip for non-leaders
            if (!$isLeader) {
                $temp = $this->applyRoundedMask($temp, $itemW, $itemH, 0.40);
            }

            // ── STEP 2: Rotate 90° clockwise (= -90deg in CSS) ──
            $bgColor = imagecolorallocatealpha($temp, 0, 0, 0, 127);
            $rotated = imagerotate($temp, -90, $bgColor); // -90 = clockwise = CSS rotate(-90deg)
            imagesavealpha($rotated, true);

            $rotW = imagesx($rotated); // after -90deg rotation: rotW = itemH, rotH = itemW
            $rotH = imagesy($rotated);

            // ── STEP 3: Position on canvas ──
            // After -90deg clockwise rotation:
            // - Moving image RIGHT on canvas = increase $destX
            // - Moving image LEFT on canvas  = decrease $destX  
            // - Moving image DOWN on canvas  = increase $destY
            // - Moving image UP on canvas    = decrease $destY
            // These now work NORMALLY — no axis swap

            $destX = (int)($zX + ($isLeader ? $zW * 0.08 : -$zW * 0.01));  // ← tune this for left/right
            $destY = (int)($currentY + ($isLeader ? 0 : (int)($itemH * -0.80))); // ← tune this for up/down

            imagecopy($canvas, $rotated, $destX, $destY, 0, 0, $rotW, $rotH);

            imagedestroy($temp);
            imagedestroy($rotated);

            $currentY += $itemH;

            // Vertical: relative to current Y position
            $yShift = $isLeader ? 0.0 : -0.40;     // ← change this to move up/down
            $destY  = (int)($currentY + $yShift * $itemH);

            // Center the (possibly padded) rotated image over the target position
            $destX -= (int)(($rotW - $itemW) / 2);
            $destY -= (int)(($rotH - $itemH) / 2);

            imagecopy($canvas, $rotated, $destX, $destY, 0, 0, $rotW, $rotH);

            imagedestroy($temp);
            imagedestroy($rotated);

            $currentY += $itemH;
        }
    }
    private function getZone2Layout(int $total): array
    {
        if ($total === 1) return [['i' => 0, 'x' => 0,   'y' => 0,   'scale' => 1,    'size' => 0.55, 'z' => 3]];
        if ($total === 2) return [
            ['i' => 1, 'x' => -20, 'y' => -18, 'scale' => 0.72, 'size' => 0.38, 'z' => 1],
            ['i' => 0, 'x' => 0,   'y' => 0,   'scale' => 1,    'size' => 0.55, 'z' => 3],
        ];
        if ($total === 3) return [
            ['i' => 1, 'x' => -22, 'y' => -18, 'scale' => 0.72, 'size' => 0.38, 'z' => 1],
            ['i' => 2, 'x' =>  22, 'y' => -18, 'scale' => 0.72, 'size' => 0.38, 'z' => 1],
            ['i' => 0, 'x' => 0,   'y' => 0,   'scale' => 1,    'size' => 0.55, 'z' => 3],
        ];
        if ($total === 4) return [
            ['i' => 0, 'x' => -22, 'y' => -15, 'scale' => 0.65, 'size' => 0.95, 'z' => 1, 'clip' => '0% 25% 10% 23%'],
            ['i' => 3, 'x' =>  22, 'y' => -15, 'scale' => 0.65, 'size' => 0.95, 'z' => 1, 'clip' => '0% 23% 10% 25%'],
            ['i' => 1, 'x' => -26, 'y' =>  10, 'scale' => 0.78, 'size' => 0.80, 'z' => 2, 'clip' => '0% 27% 55% 28%'],
            ['i' => 2, 'x' =>  26, 'y' =>  10, 'scale' => 0.78, 'size' => 0.80, 'z' => 2, 'clip' => '0% 27% 55% 25%'],
            ['i' => 0, 'x' => 0,   'y' =>  32, 'scale' => 1,    'size' => 0.99, 'z' => 3, 'clip' => '0% 25% 49.3% 25%'],
        ];
        return [
            ['i' => 3, 'x' => -21, 'y' => -15, 'scale' => 0.65, 'size' => 0.95, 'z' => 1],
            ['i' => 4, 'x' =>  21, 'y' => -15, 'scale' => 0.65, 'size' => 0.95, 'z' => 1],
            ['i' => 1, 'x' => -21, 'y' =>  10, 'scale' => 0.78, 'size' => 0.80, 'z' => 2, 'clip' => '0% 27% 55% 28%'],
            ['i' => 2, 'x' =>  21, 'y' =>  10, 'scale' => 0.78, 'size' => 0.80, 'z' => 2, 'clip' => '0% 27% 55% 25%'],
            ['i' => 0, 'x' => 0,   'y' =>  -10, 'scale' => 1,    'size' => 0.99, 'z' => 3, 'clip' => '0% 25% 80% 25%'],
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
                // top-left
                if ($x < $rx && $y < $ry && (($x - $rx) ** 2 / $rx ** 2 + ($y - $ry) ** 2 / $ry ** 2) > 1) $inCorner = true;
                // top-right
                if ($x >= $w - $rx && $y < $ry && (($x - ($w - $rx)) ** 2 / $rx ** 2 + ($y - $ry) ** 2 / $ry ** 2) > 1) $inCorner = true;
                // bottom-left
                if ($x < $rx && $y >= $h - $ry && (($x - $rx) ** 2 / $rx ** 2 + ($y - ($h - $ry)) ** 2 / $ry ** 2) > 1) $inCorner = true;
                // bottom-right
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
        // Parse "top% right% bottom% left%"
        $parts  = explode(' ', $clip);
        $top    = (int)(floatval($parts[0]) / 100 * $h);
        $right  = (int)(floatval($parts[1]) / 100 * $w);
        $bottom = (int)(floatval($parts[2]) / 100 * $h);
        $left   = (int)(floatval($parts[3]) / 100 * $w);

        $newW = $w - $left - $right;
        $newH = $h - $top - $bottom;

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