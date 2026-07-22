<?php

namespace App\Services\TGC;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TradingBoxCompositeService
{
    public function composite(string $packTitle, string $createdFor): ?string
    {
        try {
            $templatePath = storage_path('app/private/tuckbox/tradingbox.png');

            if (! file_exists($templatePath)) {
                Log::error('Trading box template not found', ['path' => $templatePath]);

                return null;
            }

            $image = imagecreatefrompng($templatePath);
            if (! $image) {
                return null;
            }

            $width = imagesx($image);
            $height = imagesy($image);

            $fontPath = storage_path('app/private/fonts/dejavu-sans-latin-400-normal.ttf');
            $fontSize = (int) ($width * 0.010);

            // Center text in each pill using imagettfbbox
            $packTitleBox = imagettfbbox($fontSize, 0, $fontPath, strtoupper($packTitle));
            $createdForBox = imagettfbbox($fontSize, 0, $fontPath, strtoupper($createdFor));

            $packTitleTextW = $packTitleBox[2] - $packTitleBox[0];
            $createdForTextW = $createdForBox[2] - $createdForBox[0];

            // Pill centers
            $packTitleCenterX = (int) ($width * 0.365);
            $createdForCenterX = (int) ($width * 0.610);
            $textY = (int) ($height * 0.755);

            // X = center - half text width
            $packTitleX = $packTitleCenterX - (int) ($packTitleTextW / 2);
            $createdForX = $createdForCenterX - (int) ($createdForTextW / 2);

            $white = imagecolorallocate($image, 255, 255, 255);

            imagettftext($image, $fontSize, 0, $packTitleX, $textY, $white, $fontPath, strtoupper($packTitle));
            imagettftext($image, $fontSize, 0, $createdForX, $textY, $white, $fontPath, strtoupper($createdFor));

            $fileName = 'trading_box_'.time().'.png';
            $filePath = 'customized_files/'.$fileName;

            ob_start();
            imagepng($image);
            $imageData = ob_get_clean();
            imagedestroy($image);

            Storage::disk('public')->put($filePath, $imageData);

            return $filePath;

        } catch (\Exception $e) {
            Log::error('Trading box composite failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
