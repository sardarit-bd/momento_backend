<?php

namespace App\Services\TGC;

use App\DTOs\TGC\AddToCartDTO;
use App\DTOs\TGC\CreateAddressDTO;
use App\DTOs\TGC\CreateCardDTO;
use App\DTOs\TGC\CreateCardFromFaceDTO;
use App\DTOs\TGC\CreateDeckDTO;
use App\DTOs\TGC\CreateFolderDTO;
use App\DTOs\TGC\CreateGameDTO;
use App\DTOs\TGC\CreateTuckBoxDTO;
use App\DTOs\TGC\ProofCardDTO;
use App\DTOs\TGC\UpdateTuckBoxDTO;
use App\DTOs\TGC\UploadFolderFileDTO;
use App\DTOs\TGC\UploadFileDTO;
use App\Exceptions\TGC\TGCApiException;
use App\Exceptions\TGC\TGCAuthException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TGCService
{
    public function __construct(private readonly TGCSessionManager $sessionManager)
    {
    }

    public function createGame(CreateGameDTO $dto): array
    {
        $designerId = trim($this->sessionManager->getDesignerId());
        if ($designerId === '') {
            throw new TGCApiException('TGC designer_id is not configured', 500);
        }

        return $this->request('POST', '/game', [
            'name'        => $dto->name,
            'designer_id' => $designerId,
        ]);
    }

    public function createFolder(CreateFolderDTO $dto): array
    {
        return $this->request('POST', '/folder', [
            'name' => $dto->name,
        ]);
    }

    public function createDeck(CreateDeckDTO $dto): array
    {
        return $this->request('POST', '/deck', [
            'game_id' => $dto->gameId,
            'name' => $dto->name,
            'identity' => $dto->identity,
            'has_proofed_back' => $dto->hasProofedBack,
            'back_id' => $dto->backId,
        ]);
    }

    public function uploadFile(UploadFileDTO $dto): array
    {
        return $this->requestMultipart('POST', '/file', [
            'deck_id' => $dto->deckId,
            'folder_id' => $dto->folderId,
            'label' => $dto->label,
        ], $dto->filePath, $dto->fileName, $dto->mimeType);
    }

    public function uploadFolderFile(UploadFolderFileDTO $dto): array
    {
        return $this->requestMultipart('POST', '/file', [
            'name' => $dto->name,
            'folder_id' => $dto->folderId,
            'has_proofed' => $dto->hasProofed,
        ], $dto->filePath, $dto->fileName, $dto->mimeType);
    }

    public function createCard(CreateCardDTO $dto): array
    {
        return $this->request('POST', '/card', [
            'deck_id' => $dto->deckId,
            'name' => $dto->name,
            'face_id' => $dto->faceFileId,
            'back_id' => $dto->backFileId,
            'has_proofed_face' => $dto->hasProofedFace,
            'has_proofed_back' => $dto->hasProofedBack,
        ]);
    }

    public function createCardFromFace(CreateCardFromFaceDTO $dto): array
    {
        return $this->request('POST', '/card', [
            'name' => $dto->name,
            'deck_id' => $dto->deckId,
            'face_id' => $dto->faceId,
            'has_proofed_face' => $dto->hasProofedFace,
            'has_proofed_back' => $dto->hasProofedBack,
        ]);
    }

    public function proofCard(ProofCardDTO $dto): array
    {
        return $this->request('PUT', '/card/'.$dto->cardId, [
            'has_proofed_face' => $dto->hasProofedFace,
            'has_proofed_back' => $dto->hasProofedBack,
        ]);
    }

    public function createTuckBox(CreateTuckBoxDTO $dto): array
    {
        return $this->request('POST', '/tuckbox', [
            'name'                => $dto->name,
            'game_id'             => $dto->gameId,
            'identity'            => $dto->identity,
            'outside_id'          => $dto->outsideId,
            'has_proofed_outside' => $dto->hasProofedOutside,
        ]);
    }

    public function updateTuckBox(UpdateTuckBoxDTO $dto): array
    {
        return $this->request('PUT', '/tuckbox/' . $dto->tuckboxId, [
            'outside_id'          => $dto->outsideId,
            'has_proofed_outside' => $dto->hasProofedOutside,
        ]);
    }

    public function createCart(): array
    {
        return $this->request('POST', '/cart', [
            'api_key_id' => config('services.tgc.api_key'),
            'user_id'    => $this->sessionManager->getUserId(),
        ]);
    }

    public function addSkuToCart(AddToCartDTO $dto): array
    {
        $result = $this->request('POST', '/cart/'.$dto->cartId.'/sku/'.$dto->skuId, [
            'quantity' => $dto->quantity,
        ]);

        return $result;
    }

    private function request(string $method, string $path, array $payload): array
    {
        return $this->send(function (string $sessionId) use ($method, $path, $payload) {
            $client = Http::acceptJson()
                ->timeout(30)
                ->retry(2, 200, null, false)
                ->asForm();

            $data = array_merge(['session_id' => $sessionId], $payload);
            $url = $this->buildUrl($path);
            $verb = strtoupper($method);

            return match ($verb) {
                'GET' => $client->get($url, $data),
                'POST' => $client->post($url, $data),
                'PUT' => $client->put($url, $data),
                'DELETE' => $client->delete($url, $data),
                default => $client->send($verb, $url, ['form_params' => $data]),
            };
        }, $method, $path);
    }

    private function requestMultipart(string $method, string $path, array $payload, string $filePath, string $fileName, string $mimeType): array
    {
        return $this->send(function (string $sessionId) use ($method, $path, $payload, $filePath, $fileName, $mimeType) {
            $data = array_merge(['session_id' => $sessionId], $payload);

            return Http::acceptJson()
                ->timeout(30)
                ->retry(2, 200, null, false)
                ->attach('file', fopen($filePath, 'r'), $fileName, ['Content-Type' => $mimeType])
                ->send(strtoupper($method), $this->buildUrl($path), [
                    'multipart' => collect($data)->map(
                        fn ($value, $key) => ['name' => $key, 'contents' => (string) $value]
                    )->values()->all(),
                ]);
        }, $method, $path);
    }

    private function send(callable $sender, string $method, string $path): array
    {
        $sessionId = $this->sessionManager->getSessionId();
        $response = $sender($sessionId);

        if ($response->status() === 401) {
            $this->sessionManager->flushSession();
            try {
                $newSessionId = $this->sessionManager->authenticate();
            } catch (TGCAuthException $e) {
                throw new TGCAuthException('TGC authentication failed', previous: $e);
            }

            $response = $sender($newSessionId);
            if ($response->status() === 401) {
                throw new TGCAuthException('TGC authentication failed');
            }
        }

        return $this->handleResponse($response);
    }

    private function handleResponse(Response $response): array
    {
        if (! $response->successful()) {
            Log::error('TGC response error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $message = (string) (data_get($response->json(), 'error.message')
                ?? data_get($response->json(), 'message')
                ?? 'TGC request failed');

            throw new TGCApiException($message, $response->status());
        }

        return $response->json() ?? [];
    }

    public function createAddress(CreateAddressDTO $dto): array
    {
        return $this->request('POST', '/address', [
            'name'         => $dto->name,
            'company'      => $dto->company,
            'address1'     => $dto->address1,
            'address2'     => $dto->address2,
            'city'         => $dto->city,
            'state'        => $dto->state,
            'postal_code'  => $dto->postalCode,
            'country'      => $dto->country,
            'phone_number' => $dto->phoneNumber,
        ]);
    }

    public function getAddress(string $addressId): array
    {
        return $this->request('GET', '/address/' . $addressId, []);
    }

    public function getCart(string $cartId): array
    {
        return $this->request('GET', '/cart/'.$cartId, []);
    }

    public function getCartItems(string $cartId): array
    {
        return $this->request('GET', '/cart/'.$cartId.'/items', []);
    }

    public function updateCart(string $cartId, array $data): array
    {
        return $this->request('PUT', '/cart/'.$cartId, $data);
    }

    public function uploadCardFaceImage(string $deckId, string $folderId, string $cardName, string $absoluteImagePath): array
    {
        $mimeType = mime_content_type($absoluteImagePath) ?: 'image/jpeg';

        return $this->requestMultipart('POST', '/file', [
            'name'      => $cardName,
            'folder_id' => $folderId,
            'has_proofed' => false,
        ], $absoluteImagePath, basename($absoluteImagePath), $mimeType);
    }

    public function attachUserToCart(string $cartId): array
    {
        return $this->request('POST', '/cart/' . $cartId . '/user', [
            'user_id' => $this->sessionManager->getUserId(),
        ]);
    }

    public function payWithShopCredit(string $cartId): array
    {
        return $this->request('POST', '/cart/' . $cartId . '/payment/shopcredit', []);
    }

    public function fetchReceipt(string $receiptId): array
    {
        return $this->request('GET', '/receipt/' . $receiptId, []);
    }

    public function getSessionId(): string
    {
        return $this->sessionManager->getSessionId();
    }

    public function getQueueStatus(): array
    {
        return $this->request('GET', '/status/queue', []);
    }

    private function buildUrl(string $path): string
    {
        return rtrim((string) config('services.tgc.base_url'), '/').'/'.ltrim($path, '/');
    }

    public function listWebhooks(): array
    {
        $userId = $this->sessionManager->getUserId();

        return $this->request('GET', '/user/' . $userId . '/webhooks', []);
    }

    public function subscribeWebhook(string $event, string $callbackUri): array
    {
        return $this->request('POST', '/webhook', [
            'owner_class' => 'User',
            'owner_id'    => $this->sessionManager->getUserId(),
            'event'       => $event,
            'callback_uri'=> $callbackUri,
            'api_key_id'  => config('services.tgc.api_key_id'),
        ]);
    }
}
