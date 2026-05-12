<?php

namespace App\Http\Controllers\Api\TGC;

use App\Http\Controllers\Controller;
use App\Http\Requests\TGC\PublishDeckRequest;
use App\Jobs\TGC\PublishDeckJob;
use App\Services\TGC\CardMergeService;
use App\Services\TGC\TGCService;
use App\DTOs\TGC\CreateGameDTO;
use App\DTOs\TGC\CreateFolderDTO;
use App\DTOs\TGC\CreateDeckDTO;
use App\DTOs\TGC\CreateTuckBoxDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DeckPublishController extends Controller
{
    public function __construct(
        private readonly CardMergeService $cardMerge,
        private readonly TGCService       $tgc,
    ) {}

    public function publish(PublishDeckRequest $request): JsonResponse
    {
        $jobId    = (string) Str::uuid();
        $username = $request->input('username');

        // Create Game 
        $game     = $this->tgc->createGame(new CreateGameDTO(name: $username));
        $gameId   = data_get($game, 'id')
            ?? throw new \RuntimeException('TGC game creation failed: no id');
        $skuId    = data_get($game, 'sku_id')
            ?? throw new \RuntimeException('TGC game creation failed: no sku_id');

        // Create Folder 
        $folder   = $this->tgc->createFolder(new CreateFolderDTO(name: "{$username}-folder"));
        $folderId = data_get($folder, 'id')
            ?? throw new \RuntimeException('TGC folder creation failed: no id');

        // Create Deck
        $deck     = $this->tgc->createDeck(new CreateDeckDTO(
            gameId:        $gameId,
            name:          "{$username}-deck",
            identity:      'PokerDeck',
            hasProofedBack: false,
            backId:        null,
        ));
        $deckId   = data_get($deck, 'id')
            ?? throw new \RuntimeException('TGC deck creation failed: no id');

        // Create TuckBox 
        $tuckbox   = $this->tgc->createTuckBox(new CreateTuckBoxDTO(
            name:   "{$username}-box",
            gameId: $gameId,
        ));
        $tuckboxId = data_get($tuckbox, 'id')
            ?? throw new \RuntimeException('TGC tuckbox creation failed: no id');

        // Create Cart
        $cart   = $this->tgc->createCart();
        $cartId = data_get($cart, 'id')
            ?? throw new \RuntimeException('TGC cart creation failed: no id');

        // Merge Cards
        $merged = $this->cardMerge->merge(
            customCards: $request->file('cards'),
            jobId:       $jobId,
        );

        // Store Box Image as Temp File
        $boxFile    = $request->file('box');
        $boxTempDir = 'private/temp/' . $jobId . '/box';
        $boxPath    = $boxFile->storeAs($boxTempDir, 'tuckbox.' . $boxFile->extension(), 'local');
        $boxAbsolutePath = Storage::disk('local')->path($boxPath);

        // Seed Cache
        Cache::put("tgc_job:{$jobId}", [
            'status'     => 'queued',
            'message'    => 'Job queued, waiting to start...',
            'job_id'     => $jobId,
            'total'      => 54,
            'uploaded'   => 0,
            'updated_at' => now()->toISOString(),
        ], now()->addHours(2));

        // Dispatch Job
        PublishDeckJob::dispatch(
            jobId:           $jobId,
            deckId:          $deckId,
            folderId:        $folderId,
            cartId:          $cartId,
            skuId:           $skuId,
            tuckboxId:       $tuckboxId,
            cardStoragePaths: $merged['paths'],
            tempDir:         $merged['tempDir'],
            boxAbsolutePath: $boxAbsolutePath,
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