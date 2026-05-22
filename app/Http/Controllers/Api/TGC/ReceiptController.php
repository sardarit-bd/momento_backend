<?php

namespace App\Http\Controllers\Api\TGC;

use App\Http\Controllers\Controller;
use App\Services\TGC\TGCService;
use Illuminate\Http\JsonResponse;

class ReceiptController extends Controller
{
    public function __construct(private readonly TGCService $tgc) {}

    public function show(string $receiptId): JsonResponse
    {
        $receipt = $this->tgc->fetchReceipt($receiptId);

        return response()->json([
            'success' => true,
            'data'    => $receipt['result'] ?? $receipt,
        ]);
    }
}