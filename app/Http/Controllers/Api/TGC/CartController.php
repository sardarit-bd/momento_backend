<?php

namespace App\Http\Controllers\Api\TGC;

use App\Actions\TGC\AddSkuToCartAction;
use App\Actions\TGC\CreateCartAction;
use App\DTOs\TGC\AddToCartDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\TGC\AddToCartRequest;
use App\Http\Resources\TGC\CartResource;
use App\Services\TGC\TGCService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        private readonly CreateCartAction $createCartAction,
        private readonly AddSkuToCartAction $addSkuToCartAction,
        private readonly TGCService $tgcService,
    ) {
    }

    public function items(string $cartId)
    {
        return response()->json($this->tgcService->getCartItems($cartId));
    }

    public function store(Request $request)
    {
        $payload = $this->createCartAction->handle();

        return (new CartResource($payload))->response()->setStatusCode(201);
    }

    public function addItem(AddToCartRequest $request, string $cartId)
    {
        $payload = $this->addSkuToCartAction->handle(
            AddToCartDTO::fromRequest($request, $cartId)
        );

        return (new CartResource($payload))->response()->setStatusCode(200);
    }
}
