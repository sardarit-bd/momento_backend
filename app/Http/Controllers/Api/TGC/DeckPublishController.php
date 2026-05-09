<?php

namespace App\Http\Controllers\Api\TGC;

use App\Http\Controllers\Controller;
use App\Http\Requests\TGC\PublishDeckRequest;
use App\Jobs\TGC\PublishDeckJob;
use App\Services\TGC\CardMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DeckPublishController extends Controller
{
    public function __construct(private readonly CardMergeService $cardMerge) {}

    public function publish(PublishDeckRequest $request): JsonResponse
    {
        $jobId = (string) Str::uuid();

        // Merge custom cards with the 54 base cards
        $merged = $this->cardMerge->merge(
            customCards: $request->file('cards'),
            jobId:       $jobId,
        );

        // Seed initial cache status
        Cache::put("tgc_job:{$jobId}", [
            'status'     => 'queued',
            'message'    => 'Job queued, waiting to start...',
            'job_id'     => $jobId,
            'total'      => 54,
            'uploaded'   => 0,
            'updated_at' => now()->toISOString(),
        ], now()->addHours(2));

        PublishDeckJob::dispatch(
            jobId:            $jobId,
            deckId:           $request->input('deck_id'),
            folderId:         $request->input('folder_id'),
            cartId:           $request->input('cart_id'),
            skuId:            $request->input('sku_id'),
            cardStoragePaths: $merged['paths'],  
            tempDir:          $merged['tempDir'],
        );

        return response()->json([
            'message'    => 'Deck publish job queued.',
            'job_id'     => $jobId,
            'status_url' => route('tgc.publish.status', ['jobId' => $jobId]),
        ], 202);
    }

    public function status(string $jobId): JsonResponse
    {
        $status = Cache::get("tgc_job:{$jobId}");

        if (!$status) {
            return response()->json(['message' => 'Job not found or expired.'], 404);
        }

        return response()->json($status);
    }
}