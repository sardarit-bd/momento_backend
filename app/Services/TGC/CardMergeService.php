<?php

namespace App\Services\TGC;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CardMergeService
{
    private const BASE_CARDS_PATH = 'private/cards';
    private const TEMP_BASE_PATH  = 'private/temp';

    // All 54 base card filenames in order
    private const BASE_CARD_NAMES = [
        'Clubs_Ace.png',
        'Clubs_Face_Jack.png',
        'Clubs_Face_King.png',
        'Clubs_Face_Queen.png',
        'Clubs_Number_10.png',
        'Clubs_Number_2.png',
        'Clubs_Number_3.png',
        'Clubs_Number_4.png',
        'Clubs_Number_5.png',
        'Clubs_Number_6.png',
        'Clubs_Number_7.png',
        'Clubs_Number_8.png',
        'Clubs_Number_9.png',
        'Diamonds_Ace.png',
        'Diamonds_Face_Jack.png',
        'Diamonds_Face_King.png',
        'Diamonds_Face_Queen.png',
        'Diamonds_Number_10.png',
        'Diamonds_Number_2.png',
        'Diamonds_Number_3.png',
        'Diamonds_Number_4.png',
        'Diamonds_Number_5.png',
        'Diamonds_Number_6.png',
        'Diamonds_Number_7.png',
        'Diamonds_Number_8.png',
        'Diamonds_Number_9.png',
        'Hearts_Ace.png',
        'Hearts_Face_Jack.png',
        'Hearts_Face_King.png',
        'Hearts_Face_Queen.png',
        'Hearts_Number_10.png',
        'Hearts_Number_2.png',
        'Hearts_Number_3.png',
        'Hearts_Number_4.png',
        'Hearts_Number_5.png',
        'Hearts_Number_6.png',
        'Hearts_Number_7.png',
        'Hearts_Number_8.png',
        'Hearts_Number_9.png',
        'Joker_1.png',
        'Joker_2.png',
        'Spades_Ace.png',
        'Spades_Face_Jack.png',
        'Spades_Face_King.png',
        'Spades_Face_Queen.png',
        'Spades_Number_10.png',
        'Spades_Number_2.png',
        'Spades_Number_3.png',
        'Spades_Number_4.png',
        'Spades_Number_5.png',
        'Spades_Number_6.png',
        'Spades_Number_7.png',
        'Spades_Number_8.png',
        'Spades_Number_9.png',
    ];

    // The 18 customizable card filenames
    private const CUSTOMIZABLE_CARDS = [
        'Clubs_Ace.png',
        'Diamonds_Ace.png',
        'Hearts_Ace.png',
        'Spades_Ace.png',
        'Clubs_Face_King.png',
        'Diamonds_Face_King.png',
        'Hearts_Face_King.png',
        'Spades_Face_King.png',
        'Clubs_Face_Queen.png',
        'Diamonds_Face_Queen.png',
        'Hearts_Face_Queen.png',
        'Spades_Face_Queen.png',
        'Clubs_Face_Jack.png',
        'Diamonds_Face_Jack.png',
        'Hearts_Face_Jack.png',
        'Spades_Face_Jack.png',
        'Joker_1.png',
        'Joker_2.png',
    ];

    /**
     * Merge custom uploaded cards with the 54 base cards.
     * Returns absolute paths for all 54 cards in order.
     * Temp files are created for custom cards — caller must cleanup using the jobId.
     *
     * @param  UploadedFile[]  $customCards  Keyed or unkeyed array of uploaded files
     * @param  string          $jobId        Used to namespace temp files
     * @return array{paths: string[], tempDir: string}
     */
    public function merge(array $customCards, string $jobId): array
    {
        // Build base array: filename => absolute path
        $cardMap = $this->buildBaseCardMap();

        // Process each custom card
        $tempDir = self::TEMP_BASE_PATH . '/' . $jobId;

        foreach ($customCards as $uploadedFile) {
            $originalName  = $uploadedFile->getClientOriginalName();
            $targetFilename = $this->resolveTargetFilename($originalName);

            if ($targetFilename === null) {
                // Not a recognized customizable card — skip
                continue;
            }

            // Store temp copy (never overwrite base)
            $tempPath = $uploadedFile->storeAs($tempDir, $targetFilename, 'local');
            $cardMap[$targetFilename] = Storage::disk('local')->path($tempPath);
        }

        // Build final ordered 54-card array
        $orderedPaths = array_map(
            fn(string $name) => $cardMap[$name],
            self::BASE_CARD_NAMES
        );

        return [
            'paths'   => $orderedPaths,   // 54 absolute paths in order
            'tempDir' => $tempDir,        // for cleanup after job
        ];
    }

    /**
     * Delete all temp files created for a job.
     */
    public function cleanup(string $tempDir): void
    {
        Storage::disk('local')->deleteDirectory($tempDir);
    }

    /**
     * Resolve which base card filename an uploaded file should replace.
     * Joker detection: filename contains "joker" (case-insensitive) → always Joker_1.png
     * Others: must exactly match a customizable card filename.
     */
    private function resolveTargetFilename(string $originalName): ?string
    {
        if (stripos($originalName, 'joker') !== false) {
            return 'Joker_1.png';
        }

        if (in_array($originalName, self::CUSTOMIZABLE_CARDS, true)) {
            return $originalName;
        }

        return null; // Not customizable
    }

    /**
     * Build a map of filename => absolute path for all 54 base cards.
     */
    private function buildBaseCardMap(): array
    {
        $map = [];

        foreach (self::BASE_CARD_NAMES as $name) {
            $relativePath = self::BASE_CARDS_PATH . '/' . $name;
            $absolutePath = Storage::disk('local')->path($relativePath);

            if (!file_exists($absolutePath)) {
                throw new \RuntimeException("Base card missing: {$name}");
            }

            $map[$name] = $absolutePath;
        }

        return $map;
    }
}