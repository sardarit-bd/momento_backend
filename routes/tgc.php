<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TGC\CardController;
use App\Http\Controllers\Api\TGC\CartController;
use App\Http\Controllers\Api\TGC\DeckController;
use App\Http\Controllers\Api\TGC\FileController;
use App\Http\Controllers\Api\TGC\FolderController;
use App\Http\Controllers\Api\TGC\GameController;

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
});
