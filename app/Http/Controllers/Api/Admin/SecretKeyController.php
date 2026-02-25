<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SecretKey;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SecretKeyController extends Controller
{
    public function index(): JsonResponse
    {
        $secrets = SecretKey::select('id', 'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_key', 'is_active', 'updated_at')
            ->get();

        return response()->json([
            'data' => $secrets
        ]);
    }

    public function show(SecretKey $secret): JsonResponse
    {
        return response()->json([
            'data' => [
                'id'                      => $secret->id,
                'stripe_publishable_key'  => $secret->stripe_publishable_key,
                'stripe_secret_key'      => $secret->stripe_secret_key,
                'stripe_webhook_key'      => $secret->stripe_webhook_key,
                'is_active'               => $secret->is_active,
                'updated_at'              => $secret->updated_at,
            ]
        ]);
    }

    // public function store(Request $request): JsonResponse
    // {
    //     $validated = $request->validate([
    //         'stripe_publishable_key' => 'required|string|unique:secret_keys,stripe_publishable_key',
    //         'stripe_secret_key'     => 'required|string|unique:secret_keys,stripe_secret_key',
    //         'stripe_webhook_key'     => 'required|string|unique:secret_keys,stripe_webhook_key',
    //     ]);

    //     $secret = SecretKey::create($validated);

    //     return response()->json([
    //         'message' => 'Stripe keys created successfully',
    //         'data'    => $secret->only(['id', 'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_key', 'is_active'])
    //     ], 201);
    // }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stripe_publishable_key' => 'required|string',
            'stripe_secret_key'      => 'required|string',
            'stripe_webhook_key'     => 'required|string',
        ]);

        $secret = SecretKey::updateOrCreate(
            ['id' => 4],
            $validated
        );

        return response()->json([
            'message' => 'Stripe keys saved successfully',
            'data'    => $secret->only(['id', 'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_key', 'is_active'])
        ], 200);
    }

    public function update(Request $request, SecretKey $secret): JsonResponse
    {
        $validated = $request->validate([
            'stripe_publishable_key' => 'sometimes|string|unique:secret_keys,stripe_publishable_key,' . $secret->id,
            'stripe_secret_key'     => 'sometimes|string|unique:secret_keys,stripe_secret_key,' . $secret->id,
            'stripe_webhook_key'     => 'sometimes|string|unique:secret_keys,stripe_webhook_key,' . $secret->id,
            'is_active'              => 'sometimes|boolean',
        ]);

        $secret->update($validated);

        return response()->json([
            'message' => 'Stripe keys updated successfully',
            'data'    => $secret->only(['id', 'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_key', 'is_active'])
        ]);
    }

    public function destroy(SecretKey $secret): JsonResponse
    {
        $secret->update(['is_active' => false]);

        return response()->json(['message' => 'Stripe keys deactivated']);
    }

    public function restore(SecretKey $secret): JsonResponse
    {
        $secret->update(['is_active' => true]);

        return response()->json(['message' => 'Stripe keys restored']);
    }

    public function getActive(): JsonResponse
    {
        $secret = SecretKey::where('is_active', true)->first();

        if (!$secret) {
            return response()->json(['error' => 'No active Stripe keys found'], 404);
        }

        return response()->json([
            'data' => [
                'stripe_publishable_key' => $secret->stripe_publishable_key,
                'stripe_secret_key'     => $secret->stripe_secret_key,
                'stripe_webhook_key'     => $secret->stripe_webhook_key,
            ]
        ]);
    }
}