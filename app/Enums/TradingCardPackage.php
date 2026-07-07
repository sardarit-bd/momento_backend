<?php

namespace App\Enums;

enum TradingCardPackage: string
{
    case Single = 'single';
    case Trio = 'trio';
    case Collection = 'collection';

    public function price(): int
    {
        return match ($this) {
            self::Single => 2900,
            self::Trio => 3900,
            self::Collection => 5400,
        };
    }

    public function cardCount(): int
    {
        return match ($this) {
            self::Single => 1,
            self::Trio => 3,
            self::Collection => 6,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Single Pack',
            self::Trio => 'Trio Pack',
            self::Collection => 'Collector Pack',
        };
    }
}