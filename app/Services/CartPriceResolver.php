<?php

namespace App\Services;

use App\Models\Product;
use App\Models\TradingCardPackage;

/**
 * Single source of truth for cart line pricing.
 */
class CartPriceResolver
{
    const TAX_RATE = 0.08;

    const JOKER_ADDON_PRICE = 7.00;

    /**
     * Resolve the authoritative unit price for a single cart line.
     *
     * @param  array{product_id: int, package_slug?: ?string, has_joker?: bool}  $item
     * @return array{base_price: float, joker_addon: float, unit_price: float}
     */
    public function resolveUnitPrice(array $item): array
    {
        $product = Product::findOrFail($item['product_id']);

        // Trading cards: price comes from the selected package, not the
        // product row itself.
        if ($product->type === 'trading' && ! empty($item['package_slug'])) {
            $package = TradingCardPackage::where('slug', $item['package_slug'])
                ->where('is_active', true)
                ->first();

            if ($package) {
                $basePrice = round($package->price_cents / 100, 2);

                return [
                    'base_price' => $basePrice,
                    'joker_addon' => 0.0,
                    'unit_price' => $basePrice,
                ];
            }

            // Package slug was invalid/inactive — fail loudly rather than
            // silently falling back to the base product price, since that
            // would let a stale/tampered slug slip through unnoticed.
            abort(422, 'Selected package is no longer available.');
        }

        // Simple / customizable products: price straight from the catalog.
        $basePrice = round((float) ($product->final_price ?? $product->price ?? 0), 2);
        $jokerAddon = ! empty($item['has_joker']) ? self::JOKER_ADDON_PRICE : 0.0;
        $unitPrice = round($basePrice + $jokerAddon, 2);

        return [
            'base_price' => $basePrice,
            'joker_addon' => $jokerAddon,
            'unit_price' => $unitPrice,
        ];
    }

    /**
     * Resolve a full cart into priced line items + totals.
     *
     * @param  array<int, array{product_id:int, qty:int, package_slug?:?string, has_joker?: bool}>  $items
     */
    public function priceCart(array $items): array
    {
        $lines = [];
        $subtotal = 0;

        foreach ($items as $item) {
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $pricing = $this->resolveUnitPrice($item);
            $lineTotal = round($pricing['unit_price'] * $qty, 2);

            $lines[] = [
                'product_id' => (int) $item['product_id'],
                'package_slug' => $item['package_slug'] ?? null,
                'qty' => $qty,
                'has_joker' => ! empty($item['has_joker']),
                'base_unit_price' => $pricing['base_price'],
                'joker_addon' => $pricing['joker_addon'],
                'unit_price' => $pricing['unit_price'],
                'line_total' => $lineTotal,
            ];

            $subtotal += $lineTotal;
        }

        $subtotal = round($subtotal, 2);
        $tax = round($subtotal * self::TAX_RATE, 2);
        $total = round($subtotal + $tax, 2);

        return compact('lines', 'subtotal', 'tax', 'total');
    }
}
