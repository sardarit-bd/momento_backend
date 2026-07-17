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
     * Regression check (plan Step 4): with x_fraction = 0 and y_fraction = 0 for
     * every image, the backend output must exactly match the undragged default
     * fan layout — i.e. an image that omits the fraction keys entirely (which
     * also defaults to 0) must produce a byte-identical composite. This proves
     * the dragged-offset math did not alter the default (undragged) layout.
     */
    public function test_zero_fraction_matches_default_fan_layout(): void
    {
        $service = new PhotoPortraitBoxCompositeService();

        $src = $this->makeImageDataUrl(120, 80, 200);

        $withZeroFractions = [
            ['src' => $src, 'x_fraction' => 0.0, 'y_fraction' => 0.0, 'zoom' => 1.0],
            ['src' => $src, 'x_fraction' => 0.0, 'y_fraction' => 0.0, 'zoom' => 1.0],
            ['src' => $src, 'x_fraction' => 0.0, 'y_fraction' => 0.0, 'zoom' => 1.0],
        ];

        $withoutFractions = [
            ['src' => $src, 'zoom' => 1.0],
            ['src' => $src, 'zoom' => 1.0],
            ['src' => $src, 'zoom' => 1.0],
        ];

        $blobZero = $service->composite($withZeroFractions, 2325, 1950);
        $blobDefault = $service->composite($withoutFractions, 2325, 1950);

        $this->assertNotNull($blobZero, 'composite returned null for zero-fraction input');
        $this->assertNotNull($blobDefault, 'composite returned null for default (no-fraction) input');
        $this->assertSame(
            $blobZero,
            $blobDefault,
            'Zero-fraction output must exactly match the undragged default fan layout'
        );
    }

    /**
     * Sanity: a positive fraction shifts the composite so it is NOT identical to
     * the default, confirming fractions actually drive position.
     */
    public function test_nonzero_fraction_changes_output(): void
    {
        $service = new PhotoPortraitBoxCompositeService();
        $src = $this->makeImageDataUrl(120, 80, 200);

        $default = [['src' => $src, 'zoom' => 1.0]];
        $dragged = [['src' => $src, 'x_fraction' => 0.25, 'y_fraction' => 0.1, 'zoom' => 1.0]];

        $blobDefault = $service->composite($default, 2325, 1950);
        $blobDragged = $service->composite($dragged, 2325, 1950);

        $this->assertNotNull($blobDefault);
        $this->assertNotNull($blobDragged);
        $this->assertNotSame($blobDefault, $blobDragged, 'A non-zero fraction must change the output');
    }
}
