<?php

namespace App\Services\TGC;

use App\DTOs\TGC\AddToCartDTO;
use App\DTOs\TGC\CreateCardDTO;
use App\DTOs\TGC\CreateCardFromFaceDTO;
use App\DTOs\TGC\CreateDeckDTO;
use App\DTOs\TGC\CreateFolderDTO;
use App\DTOs\TGC\CreateGameDTO;
use App\DTOs\TGC\ProofCardDTO;
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

        Log::debug('TGC designer_id', [
            'designer_id' => $designerId,
        ]);

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
        ]);
    }

    public function uploadFile(UploadFileDTO $dto): array
    {
        return $this->requestMultipart('POST', '/files', [
            'deck_id' => $dto->deckId,
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
            'has_proofed_face' => 1,
            'has_proofed_back' => 1,
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

    public function createCart(): array
    {
        return $this->request('POST', '/cart', []);
    }

    public function addSkuToCart(AddToCartDTO $dto): array
    {
        Log::debug('addSkuToCart DTO', [
            'cartId' => $dto->cartId,
            'skuId' => $dto->skuId,
            'quantity' => $dto->quantity,
        ]);

        return $this->request('POST', '/cart/'.$dto->cartId.'/sku/'.$dto->skuId, [
            'quantity' => $dto->quantity,
        ]);
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
        Log::debug('TGC request', [
            'method' => $method,
            'url' => $this->buildUrl($path),
        ]);

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
        Log::debug('TGC raw response', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);
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

    private function buildUrl(string $path): string
    {
        return rtrim((string) config('services.tgc.base_url'), '/').'/'.ltrim($path, '/');
    }
}
