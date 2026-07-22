<?php

namespace Database\Seeders;

use App\Models\TradingCardPackage;
use Illuminate\Database\Seeder;

class TradingCardPackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'slug' => 'single',
                'name' => 'Single Pack',
                'tag' => 'Simple Start',
                'subtitle' => 'For trying out your first design',
                'card_count' => 1,
                'price_cents' => 2900,
                'features' => ['1 unique design', '18 printed cards', 'Standard layout system'],
                'recommended' => false,
                'sort_order' => 1,
            ],
            [
                'slug' => 'trio',
                'name' => 'Trio Pack',
                'tag' => 'Recommended',
                'subtitle' => 'Balanced choice for most users',
                'card_count' => 3,
                'price_cents' => 3900,
                'features' => ['3 unique designs', '18 total cards', 'Balanced variation'],
                'recommended' => true,
                'sort_order' => 2,
            ],
            [
                'slug' => 'collection',
                'name' => 'Collector Pack',
                'tag' => 'Maximum Variety',
                'subtitle' => 'For full creative expression',
                'card_count' => 6,
                'price_cents' => 5400,
                'features' => ['6 unique designs', '18 total cards', 'Maximum variation'],
                'recommended' => false,
                'sort_order' => 3,
            ],
        ];

        foreach ($packages as $pkg) {
            TradingCardPackage::updateOrCreate(['slug' => $pkg['slug']], $pkg);
        }
    }
}
