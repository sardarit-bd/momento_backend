<?php

namespace Tests\Support;

class TGCFakeResponseFactory
{
    public static function session(): array
    {
        return ['session_id' => 'sess_123'];
    }

    public static function game(): array
    {
        return ['id' => 'game_1', 'name' => 'My Game', 'description' => 'Demo'];
    }

    public static function deck(): array
    {
        return ['id' => 'deck_1', 'game_id' => 'game_1', 'name' => 'Main Deck', 'card_count' => 54];
    }

    public static function file(string $id = 'file_1'): array
    {
        return ['id' => $id, 'name' => 'card.png'];
    }

    public static function card(): array
    {
        return ['id' => 'card_1', 'deck_id' => 'deck_1', 'name' => 'Ace', 'face_id' => 'file_1', 'back_id' => 'file_2'];
    }

    public static function proofedCard(): array
    {
        return ['id' => 'card_1', 'deck_id' => 'deck_1', 'name' => 'Ace', 'has_proofed_face' => true, 'has_proofed_back' => true];
    }

    public static function cart(): array
    {
        return ['id' => 'cart_1', 'status' => 'active', 'items' => []];
    }

    public static function cartWithItem(): array
    {
        return ['id' => 'cart_1', 'status' => 'active', 'items' => [['sku_id' => 'sku_1', 'quantity' => 2]]];
    }

    public static function error(string $message = 'Upstream failure'): array
    {
        return ['message' => $message];
    }
}
