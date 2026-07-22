<?php

namespace App\Http\Controllers\Api\TGC;

use App\Http\Controllers\Controller;
use App\Services\TGC\TGCService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatusController extends Controller
{
    /**
     * TGC does not expose shipping transit days via API (only production
     * ship date). This is a static lookup based on their published
     * shipping methods page — adjust the key/values to match whichever
     * method(s) this app actually offers at checkout.
     */
    private const SHIPPING_TRANSIT_DAYS = [
        'standard' => [5, 8],
        'ups_ground' => [3, 6],
        'ups_2day' => [2, 2],
        'overnight' => [1, 1],
    ];

    public function __construct(private readonly TGCService $tgcService) {}

    /**
     * GET /tgc/status/queue
     *
     * Live production queue estimate from TGC. Use this to show a rough
     * "estimated production time" BEFORE a cart exists yet — e.g. on the
     * product page or package select page.
     */
    public function queue(): JsonResponse
    {
        $queue = $this->tgcService->getQueueStatus();

        // Wing API wraps payloads in a "result" key; TGCService already
        // unwraps the HTTP layer but not this — handle both shapes safely.
        $durationSeconds = (int) data_get($queue, 'result.duration', data_get($queue, 'duration', 0));
        $productionDays = $durationSeconds > 0 ? (int) ceil($durationSeconds / 86400) : null;

        return response()->json([
            'production_days_estimate' => $productionDays,
            'raw' => $queue,
        ]);
    }

    /**
     * GET /tgc/carts/{cartId}/estimate
     *
     * Precise delivery estimate for a real TGC cart, combining TGC's own
     * estimated_ship_date (accounts for current queue load + item
     * complexity) with our static shipping-transit lookup. This is the
     * number to show once a TGC cart actually exists (checkout page).
     */
    public function cartEstimate(Request $request, string $cartId): JsonResponse
    {
        $cart = $this->tgcService->getCart($cartId);

        $estimatedShipDate = data_get(
            $cart,
            'result.estimated_ship_date',
            data_get($cart, 'estimated_ship_date')
        );

        if (! $estimatedShipDate) {
            return response()->json([
                'estimated_ship_date' => null,
                'estimated_delivery_min_date' => null,
                'estimated_delivery_max_date' => null,
            ]);
        }

        $shipDate = Carbon::parse($estimatedShipDate);

        $method = $request->query('shipping_method', config('services.tgc.default_shipping_method', 'standard'));
        [$transitMin, $transitMax] = self::SHIPPING_TRANSIT_DAYS[$method] ?? self::SHIPPING_TRANSIT_DAYS['standard'];

        return response()->json([
            'estimated_ship_date' => $shipDate->toDateString(),
            'estimated_delivery_min_date' => $shipDate->copy()->addWeekdays($transitMin)->toDateString(),
            'estimated_delivery_max_date' => $shipDate->copy()->addWeekdays($transitMax)->toDateString(),
        ]);
    }
}
