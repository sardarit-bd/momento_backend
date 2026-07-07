<?php

use App\Http\Controllers\Api\TGC\AddressController;
use App\Http\Controllers\Api\TGC\DeckPublishController;
use App\Services\TGC\TGCService;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TGC\CardController;
use App\Http\Controllers\Api\TGC\CartController;
use App\Http\Controllers\Api\TGC\DeckController;
use App\Http\Controllers\Api\TGC\FileController;
use App\Http\Controllers\Api\TGC\FolderController;
use App\Http\Controllers\Api\TGC\GameController;
use App\Http\Controllers\Api\TGC\ReceiptController;
use App\Http\Controllers\Api\TGC\StatusController;

Route::middleware('auth:api')->prefix('tgc')->group(function (): void {
    Route::post('/games', [GameController::class, 'store']);
    Route::post('/folders', [FolderController::class, 'store']);
    Route::post('/files', [FileController::class, 'store']);
    Route::post('/decks', [DeckController::class, 'storeDirect']);
    Route::post('/games/{gameId}/decks', [DeckController::class, 'store']);
    Route::post('/decks/{deckId}/cards', [CardController::class, 'store']);
    Route::post('/cards', [CardController::class, 'storeFromFace']);
    Route::put('/cards/{cardId}/proof', [CardController::class, 'proof']);
    Route::post('/carts', [CartController::class, 'store']);
    Route::post('/carts/{cartId}/items', [CartController::class, 'addItem']);

    Route::get('/carts/{cartId}', fn(string $cartId) => app(TGCService::class)->getCart($cartId));
    
    Route::get('/carts/{cartId}/items', [CartController::class, 'items']);

    Route::post('/addresses', [AddressController::class, 'store']);
    Route::put('/carts/{cartId}', [CartController::class, 'update']);

    Route::post('/publish',                [DeckPublishController::class, 'publish'])->name('tgc.publish');
    Route::get('/publish/{jobId}/status',  [DeckPublishController::class, 'status'])->name('tgc.publish.status');

    Route::get('/receipts/{receiptId}', [ReceiptController::class, 'show']);
    Route::get('/addresses/{addressId}', [AddressController::class, 'show']);

    Route::get('/status/queue', [StatusController::class, 'queue']);
    Route::get('/carts/{cartId}/estimate', [StatusController::class, 'cartEstimate']);
});
