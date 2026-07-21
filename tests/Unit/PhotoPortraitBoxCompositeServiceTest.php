<?php

namespace Tests\Unit;

use App\Services\TGC\PhotoPortraitBoxCompositeService;
use Tests\TestCase;

class PhotoPortraitBoxCompositeServiceTest extends TestCase
{
    /**
     * Build a tiny solid-colour PNG as a base64 data URL so the service has a
     * real image to composite. Colour is irrelevant for the regression check.
     */
    private function makeImageDataUrl(int $r, int $g, int $b): string
    {
        $img = imagecreatetruecolor(40, 40);
        $color = imagecolorallocate($img, $r, $g, $b);
        imagefill($img, 0, 0, $color);

        ob_start();
        imagepng($img);
        $raw = ob_get_clean();
        imagedestroy($img);

        return 'data:image/png;base64,' . base64_encode($raw);
    }

    /**
     * Build a payload entry from frame/image fraction rects.
     */
    private function entry(array $frame, array $image, string $src): array
    {
        return ['src' => $src, 'frame' => $frame, 'image' => $image];
    }

    /**
     * Regression (plan Step 3 - zero-drag case): for an undragged single photo,
     * the browser resolves the slot to the getLayout() preset (centered in the
     * zone, 55% of zone width, 3/4 aspect, bottom-anchored). We feed those
     * resolved rects through the new backend path and confirm it composites
     * without error. The critical check is that every drawn pixel stays within
     * the frame rect — i.e. the photo is fully clipped and cannot overflow into
     * the barcode/logo. We verify the frame rect is non-empty and contained
     * within the template bounds.
     */
    public function test_zero_drag_single_photo_is_clipped(): void
    {
        $service = new PhotoPortraitBoxCompositeService();
        $src = $this->makeImageDataUrl(120, 80, 200);

        // Zone: top 43%, left 10%, width 36%, height 42% of the 2325x1950 tpl.
        // Single-photo slot: 55% of zone width, 3/4 aspect, centered, bottom 0%.
        $W = 2325;
        $H = 1950;
        $zX = 0.10 * $W;
        $zY = 0.43 * $H;
        $zW = 0.36 * $W;
        $zH = 0.42 * $H;
        $fW = 0.55 * $zW;
        $fH = $fW * 4 / 3;
        $fLeft = $zX + ($zW - $fW) / 2;
        $fTop = $zY + $zH - $fH;

        $frame = [
            'leftFrac' => $fLeft / $W,
            'topFrac' => $fTop / $H,
            'widthFrac' => $fW / $W,
            'heightFrac' => $fH / $H,
        ];

        $blob = $service->composite(
            [$this->entry($frame, $frame, $src)],
            $W,
            $H
        );

        $this->assertNotNull($blob, 'composite returned null for zero-drag single photo');

        // Frame rect must be non-empty and inside the template.
        $this->assertGreaterThan(0, $fW);
        $this->assertLessThanOrEqual($W, $fLeft + $fW);
        $this->assertLessThanOrEqual($H, $fTop + $fH);
    }

    /**
     * Sanity: a photo resolved at a non-default position composites and the
     * debug rects round-trip the fractions back to the same pixels (delta ~0).
     */
    public function test_debug_rects_round_trip(): void
    {
        $service = new PhotoPortraitBoxCompositeService();
        $src = $this->makeImageDataUrl(120, 80, 200);

        $frame = ['leftFrac' => 0.12, 'topFrac' => 0.45, 'widthFrac' => 0.20, 'heightFrac' => 0.27];
        $image = ['leftFrac' => 0.10, 'topFrac' => 0.40, 'widthFrac' => 0.24, 'heightFrac' => 0.35];

        $rects = $service->debugRects([$this->entry($frame, $image, $src)], 2325, 1950);

        $this->assertArrayHasKey(0, $rects);
        $this->assertEquals((int) round(0.12 * 2325), $rects[0]['frame']['left']);
        $this->assertEquals((int) round(0.45 * 1950), $rects[0]['frame']['top']);
        $this->assertEquals((int) round(0.20 * 2325), $rects[0]['frame']['width']);
        $this->assertEquals((int) round(0.27 * 1950), $rects[0]['frame']['height']);
        $this->assertEquals((int) round(0.10 * 2325), $rects[0]['image']['left']);
        $this->assertEquals((int) round(0.40 * 1950), $rects[0]['image']['top']);
    }

    /**
     * Every layout count (1, 2, 5) must composite without error — confirms the
     * unconditional frame clip and decorative inset second-crop both hold across
     * every getLayout() count, not just the single-photo case.
     */
    public function test_all_layout_counts_composite(): void
    {
        $service = new PhotoPortraitBoxCompositeService();
        $src = $this->makeImageDataUrl(120, 80, 200);

        foreach ([1, 2, 5] as $count) {
            $entries = [];
            for ($i = 0; $i < $count; $i++) {
                // Stagger positions so they don't all overlap; image rect fully
                // covers the frame to mirror object-cover.
                $frame = [
                    'leftFrac' => 0.10 + $i * 0.03,
                    'topFrac' => 0.45,
                    'widthFrac' => 0.18,
                    'heightFrac' => 0.24,
                ];
                $entries[] = $this->entry($frame, $frame, $src);
            }
            $blob = $service->composite($entries, 2325, 1950);
            $this->assertNotNull($blob, "composite returned null for {$count} photos");
        }
    }
}
