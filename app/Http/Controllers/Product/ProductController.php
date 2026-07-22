<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductHasImage;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    /**
     * Common list of all customizable relations.
     */
    private array $customRelations = [
        'skin_tones', 'hairs', 'noses', 'eyes', 'mouths',
        'dresses', 'crowns', 'base_cards', 'beards',
        'trading_fronts', 'trading_backs',
    ];

    /**
     * Display a listing of products with pagination.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $products = Product::with(array_merge(['category', 'images'], $this->customRelations))
                ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
                ->when($request->type, fn ($q) => $q->where('type', $request->type))
                ->when(! is_null($request->status), fn ($q) => $q->where('status', $request->status === 'true'))
                ->when($request->search, fn ($q) => $q->where('name', 'LIKE', "%{$request->search}%"))
                ->latest()
                ->paginate($request->get('per_page', 15));

            $products->getCollection()->transform(function ($p) {
                $p->image = $p->image ? asset('storage/'.$p->image) : null;

                $p->gallery_images = $p->images->map(fn ($img) => [
                    'id' => $img->id,
                    'url' => $img->image ? asset('storage/'.$img->image) : null,
                    'alt' => $img->alt ?? null,
                ])->toArray();

                $customizations = [];
                foreach ($this->customRelations as $relation) {
                    $customizations[$relation] = $p->{$relation}?->map(fn ($item) => [
                        'id' => $item->id,
                        'name' => $item->name,
                        'image' => $item->image ? asset('storage/'.$item->image) : null,
                    ])->toArray() ?? [];
                }
                $p->customizations = $customizations;

                return $p;
            });

            return $this->successResponse('Products fetched successfully', $products);
        } catch (Exception $e) {
            \Log::error('Product index failed: '.$e->getMessage());

            return $this->errorResponse('Failed to fetch products.');
        }
    }

    /**
     * Store a newly created product.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $this->validateProductData($request);

            DB::beginTransaction();

            $productData = $this->prepareProductData($validated);
            $product = Product::create($productData);

            // Main image
            if (! empty($validated['image'])) {
                $mainImagePath = $this->saveBase64Image($validated['image'], 'products/main');
                $product->update(['image' => $mainImagePath]);
            }

            // Gallery images
            if (! empty($validated['images'])) {
                $this->saveGalleryImages($product, $validated['images']);
            }

            // Handle customizations
            if (in_array(strtolower($product->type), ['customizable', 'trading', 'photo'])) {
                $this->handleCustomizations($product, $request);
            }

            DB::commit();

            return $this->successResponse(
                'Product created successfully',
                $this->formatSingleProduct($product->load(['category', 'images'])),
                201
            );
        } catch (ValidationException $e) {
            DB::rollBack();

            return $this->validationErrorResponse($e);
        } catch (Exception $e) {
            DB::rollBack();
            \Log::error('Product create failed: '.$e->getMessage());

            return $this->errorResponse('Failed to create product.');
        }
    }

    /**
     * Store a card product and its gallery images.
     * Endpoint: POST /cardproduct (admin only)
     */
    public function cardproduct(Request $request): JsonResponse
    {
        if (! Auth::check() || Auth::user()->role !== 'Admin') {
            return $this->unauthorizedResponse();
        }

        $storedPaths = [];

        try {

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'slug' => 'nullable|string|max:255|unique:products,slug',
                'type' => 'required|in:simple,customizable,trading,photo',
                'category_id' => 'required|integer|exists:categories,id',
                'price' => 'required|numeric|min:0',
                'offer_price' => 'nullable|numeric|min:0|lt:price',
                'status' => 'nullable|in:active,inactive,1,0,true,false,Active,Inactive,TRUE,FALSE',
                'short_description' => 'nullable|string',
                'description' => 'nullable|string',
                'image' => 'nullable',
                'images' => 'required|array|min:1',
                'images.*' => 'required',
            ]);

            $validator->after(function ($validator) use ($request) {
                $mainImage = $request->input('image', $request->file('image'));
                if (! is_null($mainImage) && ! $this->isValidImageInput($mainImage)) {
                    $validator->errors()->add('image', 'image must be a base64 string or uploaded file.');
                }

                foreach ((array) $request->input('images', []) as $index => $img) {
                    if (! $this->isValidImageInput($img)) {
                        $validator->errors()->add("images.$index", 'Each images item must be a base64 string or uploaded file.');
                    }
                }

                foreach ($request->file('images', []) as $index => $file) {
                    if (! $this->isValidImageInput($file)) {
                        $validator->errors()->add("images.$index", 'Each images item must be a base64 string or uploaded file.');
                    }
                }
            });

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Validation failed for cardproduct endpoint',
                    'errors' => $validator->errors(),
                    'debug' => [
                        'accepted_image_formats' => ['base64 string', 'multipart file'],
                        'required_fields' => ['name', 'category_id', 'price', 'images'],
                    ],
                ], 422);
            }

            $validated = $validator->validated();

            DB::beginTransaction();

            $slug = ! empty($validated['slug'])
                ? Str::slug($validated['slug'])
                : Str::slug($validated['name']);
            $slug = $slug.'-'.random_int(1000, 9999);
            $normalizedStatus = $this->normalizeProductStatus($validated['status'] ?? null);

            $product = Product::create([
                'name' => $validated['name'],
                'slug' => $slug,
                'type' => $validated['type'],
                'price' => $validated['price'],
                'offer_price' => $validated['offer_price'] ?? null,
                'status' => $normalizedStatus,
                'category_id' => $validated['category_id'],
                'short_description' => $validated['short_description'] ?? null,
                'description' => $validated['description'] ?? null,
            ]);

            $mainImageInput = $request->file('image') ?: ($validated['image'] ?? null);
            if (! empty($mainImageInput)) {
                $mainImagePath = $this->saveImageInput($mainImageInput, 'products/main');
                $storedPaths[] = $mainImagePath;
                $product->update(['image' => $mainImagePath]);
            }

            $galleryInputs = $request->file('images', []);
            if (empty($galleryInputs)) {
                $galleryInputs = $validated['images'] ?? [];
            }

            foreach ($galleryInputs as $img) {
                $path = $this->saveImageInput($img, 'products/gallery');
                $storedPaths[] = $path;
                ProductHasImage::create([
                    'product_id' => $product->id,
                    'image' => $path,
                ]);
            }

            if (in_array(strtolower($product->type), ['customizable', 'trading', 'photo'])) {
                $this->handleCustomizations($product, $request);
            }

            DB::commit();

            return $this->successResponse(
                'Card product created successfully',
                $this->formatSingleProduct($product->load(['category', 'images'])),
                201
            );
        } catch (ValidationException $e) {
            DB::rollBack();

            return $this->validationErrorResponse($e);
        } catch (Exception $e) {
            DB::rollBack();
            \Log::error('cardproduct:exception_rollback', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            foreach ($storedPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            $message = $e->getMessage();

            if ($message === 'Invalid base64 image payload') {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Invalid image payload. Send base64 image data (data:image/...;base64,...) for image and images fields.',
                ], 422);
            }

            if ($message === 'Failed to save image to storage') {
                return response()->json([
                    'success' => false,
                    'status' => 500,
                    'message' => 'Server could not write image files to storage.',
                ], 500);
            }

            return $this->errorResponse('Failed to create card product.');
        }
    }

    public function showCardProduct(string $slug): JsonResponse
    {
        try {
            $product = Product::with([
                'category',
                'images',
                'skin_tones',
                'hairs',
                'noses',
                'eyes',
                'mouths',
                'dresses',
                'crowns',
                'base_cards',
                'beards',
                'trading_fronts',
                'trading_backs',
            ])->where('slug', $slug)->first();

            if (! $product) {
                return response()->json([
                    'success' => false,
                    'status' => 404,
                    'message' => 'Product not found',
                ], 404);
            }

            $data = [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'type' => $product->type,
                'price' => $product->price,
                'offer_price' => $product->offer_price,
                'status' => (bool) $product->status,
                'short_description' => $product->short_description,
                'description' => $product->description,
                'image' => $product->image_url,
                'category' => [
                    'id' => $product->category?->id,
                    'name' => $product->category?->name,
                ],
                'gallery_images' => $product->images->map(fn ($img) => [
                    'id' => $img->id,
                    'url' => asset('storage/'.$img->image),
                ]),
            ];

            if ($product->type !== 'simple') {
                $data['customizations'] = [
                    // base_cards gets special mapping to include card_type
                    'custom_sets' => $product->base_cards->map(fn ($item) => [
                        'id' => $item->id,
                        'image' => asset('storage/'.$item->image),
                        'card_type' => $item->card_type,
                        'name' => $item->name,
                    ])->toArray(),

                    'base_cards' => $product->base_cards->map(fn ($item) => [
                        'id' => $item->id,
                        'image' => asset('storage/'.$item->image),
                        'card_type' => $item->card_type,
                        'name' => $item->name,
                    ])->toArray(),

                    // all other layers use the generic mapper
                    'skin_tones' => $this->mapLayerImages($product->skin_tones),
                    'hairs' => $this->mapLayerImages($product->hairs),
                    'noses' => $this->mapLayerImages($product->noses),
                    'eyes' => $this->mapLayerImages($product->eyes),
                    'mouths' => $this->mapLayerImages($product->mouths),
                    'dresses' => $this->mapLayerImages($product->dresses),
                    'crowns' => $this->mapLayerImages($product->crowns),
                    'beards' => $this->mapLayerImages($product->beards),
                    'trading_fronts' => $this->mapLayerImages($product->trading_fronts),
                    'trading_backs' => $this->mapLayerImages($product->trading_backs),
                ];
            }

            return response()->json([
                'success' => true,
                'status' => 200,
                'data' => $data,
            ]);

        } catch (Exception $e) {

            return $this->errorResponse('Failed to fetch product.');
        }
    }

    private function mapLayerImages($collection): array
    {
        if (! $collection || $collection->isEmpty()) {
            return [];
        }

        return $collection->map(fn ($item) => [
            'id' => $item->id,
            'image' => asset('storage/'.$item->image),
        ])->toArray();
    }

    public function updateProductStatus(Request $request): JsonResponse
    {
        if (! Auth::check() || Auth::user()->role !== 'Admin') {
            return $this->unauthorizedResponse();
        }

        try {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|exists:products,id',
                'status' => 'required|in:0,1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $product = Product::findOrFail($request->id);

            $product->status = $request->status ? 1 : 0;
            $product->save();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Product status updated to '.$product->status,
                'data' => [
                    'id' => $product->id,
                    'status' => $product->status,
                ],
            ]);

        } catch (Exception $e) {
            \Log::error('updateProductStatus failed: '.$e->getMessage(), [
                'request_user_id' => Auth::id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Failed to update product status.');
        }
    }

    private function isValidImageInput($image): bool
    {
        if ($image instanceof UploadedFile) {
            return in_array(strtolower($image->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        }

        if (! is_string($image) || trim($image) === '') {
            return false;
        }

        return $this->isLikelyBase64Image($image);
    }

    private function saveImageInput($image, string $folder): string
    {
        if ($image instanceof UploadedFile) {
            $extension = strtolower($image->getClientOriginalExtension()) ?: 'png';
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $extension = 'png';
            }

            $fileName = time().'_'.uniqid().'.'.$extension;

            return $image->storeAs($folder, $fileName, 'public');
        }

        return $this->saveBase64Image((string) $image, $folder);
    }

    private function normalizeProductStatus($status): string|int
    {
        $normalized = 'active';

        if (! is_null($status) && $status !== '') {
            $value = strtolower((string) $status);

            if (in_array($value, ['0', 'false', 'inactive'], true)) {
                $normalized = 'inactive';
            }
        }

        if ($this->statusColumnExpectsNumeric()) {
            return $normalized === 'active' ? 1 : 0;
        }

        return $normalized;
    }

    private function statusColumnExpectsNumeric(): bool
    {
        try {
            $columnType = strtolower((string) Schema::getColumnType('products', 'status'));

            return in_array($columnType, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'boolean'], true);
        } catch (Exception $e) {
            // If schema introspection fails, keep legacy enum behavior.
            return false;
        }
    }

    /**
     * Show product details.
     */
    public function show($slug): JsonResponse
    {
        try {
            $product = Product::with(array_merge(['category', 'images'], $this->customRelations))
                ->where('slug', $slug)
                ->firstOrFail();

            return $this->successResponse('Product fetched successfully', $this->formatSingleProduct($product));
        } catch (Exception $e) {
            return $this->errorResponse('Product not found', 404);
        }
    }

    /**
     * Update product.
     */
    public function update(Request $request, $id): JsonResponse
    {
        if (! Gate::allows('update-products')) {
            return $this->unauthorizedResponse();
        }

        try {
            $product = Product::findOrFail($id);
            $validated = $this->validateProductData($request, $product->id);

            DB::beginTransaction();

            $productData = $this->prepareProductData($validated);
            $product->update($productData);

            // Main image
            if (! empty($validated['image'])) {
                if ($product->image) {
                    Storage::disk('public')->delete($product->image);
                }
                $mainImagePath = $this->saveBase64Image($validated['image'], 'products/main');
                $product->update(['image' => $mainImagePath]);
            }

            // Gallery images
            if (isset($validated['images'])) {
                foreach ($product->images as $image) {
                    Storage::disk('public')->delete($image->image);
                }
                $product->images()->delete();

                if (! empty($validated['images'])) {
                    $this->saveGalleryImages($product, $validated['images']);
                }
            }

            // Customizations
            if (in_array(strtolower($product->type), ['customizable', 'trading', 'photo'])) {
                $this->handleCustomizations($product, $request, true);
            }

            DB::commit();

            return $this->successResponse(
                'Product updated successfully',
                $this->formatSingleProduct($product->load(['category', 'images']))
            );
        } catch (ValidationException $e) {
            DB::rollBack();

            return $this->validationErrorResponse($e);
        } catch (Exception $e) {
            DB::rollBack();
            \Log::error('Product update failed: '.$e->getMessage());

            return $this->errorResponse('Failed to update product.');
        }
    }

    /**
     * Delete product.
     */
    public function destroy($id): JsonResponse
    {
        try {
            $product = Product::with(array_merge(['images'], $this->customRelations))
                ->findOrFail($id);

            DB::beginTransaction();

            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            foreach ($product->images as $img) {
                Storage::disk('public')->delete($img->image);
            }

            foreach ($this->customRelations as $relation) {
                foreach ($product->{$relation} as $item) {
                    if ($item->image) {
                        Storage::disk('public')->delete($item->image);
                    }
                }
                $product->{$relation}()->delete();
            }

            $product->delete();

            DB::commit();

            return $this->successResponse('Product deleted successfully');
        } catch (Exception $e) {
            DB::rollBack();
            \Log::error('Product delete failed: '.$e->getMessage());

            return $this->errorResponse('Failed to delete product.');
        }
    }

    /**
     * Validation rules.
     */
    private function validateProductData(Request $request, $productId = null): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|unique:products,slug,'.($productId ?? 'NULL').',id',
            'type' => 'required|string|in:simple,customizable,trading,photo,Simple,Customizable,Trading,Photo',
            'price' => 'required|numeric|min:0',
            'status' => 'required|in:active,inactive',
            'offer_price' => 'nullable|numeric|min:0|lt:price',
            'category_id' => 'required|exists:categories,id',
            'short_description' => 'nullable|string',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'required|string',
        ];

        if (in_array($request->type, ['Customizable', 'Trading', 'customizable', 'trading', 'Photo', 'photo'])) {
            foreach ($this->customRelations as $field) {
                $rules[$field] = 'sometimes|array';
                $rules[$field.'.*'] = 'sometimes|string';
            }
        }

        return $request->validate($rules);
    }

    /**
     * Prepare product data.
     */
    private function prepareProductData(array $validated): array
    {
        return [
            'name' => $validated['name'],
            'slug' => ! empty($validated['slug'])
                ? $validated['slug'].'-'.rand(1000, 9999)
                : Str::slug($validated['name']).'-'.rand(1000, 9999),
            'type' => strtolower($validated['type']),
            'price' => $validated['price'],
            'status' => $validated['status'],
            'category_id' => $validated['category_id'],
            'short_description' => $validated['short_description'] ?? null,
            'description' => $validated['description'] ?? null,
            'offer_price' => $validated['offer_price'] ?? null,
        ];
    }

    /**
     * Save gallery images.
     */
    private function saveGalleryImages(Product $product, array $images): void
    {
        foreach ($images as $img) {
            if (! empty($img)) {
                $path = $this->saveBase64Image($img, 'products/gallery');
                ProductHasImage::create([
                    'product_id' => $product->id,
                    'image' => $path,
                ]);
            }
        }
    }

    /**
     * Handle customizable/trading options.
     */
    private function handleCustomizations(Product $product, Request $request, bool $isUpdate = false): void
    {
        // Handle regular flat layers
        $regularRelations = [
            'skin_tones', 'hairs', 'noses', 'eyes', 'mouths',
            'dresses', 'crowns', 'beards', 'trading_fronts', 'trading_backs',
        ];

        foreach ($regularRelations as $relation) {
            if ($request->has($relation) && is_array($request->$relation)) {
                if ($isUpdate) {
                    $product->{$relation}()->delete();
                }

                foreach ($request->$relation as $index => $base64) {
                    $path = $this->saveBase64Image($base64, "products/customizations/{$relation}");
                    $product->{$relation}()->create([
                        'name' => ucfirst($relation).' '.($index + 1),
                        'product_id' => $product->id,
                        'image' => $path,
                    ]);
                }
            }
        }

        // Handle base_cards separately — each item has {image, card_type}
        if ($request->has('base_cards') && is_array($request->base_cards)) {
            if ($isUpdate) {
                $product->base_cards()->delete();
            }

            foreach ($request->base_cards as $index => $item) {
                $base64 = is_array($item) ? $item['image'] : $item;
                $cardType = is_array($item) ? ($item['card_type'] ?? null) : null;
                $originalName = is_array($item) ? ($item['filename'] ?? null) : null;

                $path = $this->saveBase64Image(
                    $base64,
                    'products/customizations/base_cards',
                    $originalName
                );

                $product->base_cards()->create([
                    'name' => $item['name'] ?? $cardType ?? ('Base Card '.($index + 1)),
                    'product_id' => $product->id,
                    'image' => $path,
                    'card_type' => $cardType,
                ]);
            }
        }
    }

    /**
     * Save base64 image and return path.
     */
    private function saveBase64Image(string $base64Image, string $folder, ?string $originalFilename = null): string
    {
        $base64Image = trim($base64Image);

        if (preg_match('/^data:image\/(\w+);base64,/', $base64Image, $type)) {
            $imageData = substr($base64Image, strpos($base64Image, ',') + 1);
            $extension = strtolower($type[1]);
        } else {
            $imageData = $base64Image;
            $extension = 'png';
        }

        $decoded = base64_decode(str_replace(' ', '+', $imageData), true);
        if ($decoded === false || $decoded === '') {
            throw new Exception('Invalid base64 image payload');
        }

        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $extension = 'png';
        }

        // Use original filename if provided, otherwise generate random
        if ($originalFilename) {
            $fileName = pathinfo($originalFilename, PATHINFO_FILENAME).'.'.$extension;
        } else {
            $fileName = time().'_'.uniqid().'.'.$extension;
        }

        $filePath = $folder.'/'.$fileName;

        if (! Storage::disk('public')->put($filePath, $decoded)) {
            throw new Exception('Failed to save image to storage');
        }

        return $filePath;
    }

    private function isLikelyBase64Image(string $input): bool
    {
        $value = trim($input);

        if (preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,/', $value)) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $value);
    }

    /**
     * Format single product for API response.
     */
    private function formatSingleProduct($product): array
    {
        $data = [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'type' => $product->type,
            'price' => $product->price,
            'offer_price' => $product->offer_price,
            'final_price' => $product->offer_price ?? $product->price,
            'discount_percentage' => $product->offer_price
                ? round((($product->price - $product->offer_price) / $product->price) * 100, 2)
                : 0,
            'status' => $product->status,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'created_at' => $product->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $product->updated_at->format('Y-m-d H:i:s'),
            'image' => $product->image ? asset('storage/'.$product->image) : null,
        ];

        if ($product->relationLoaded('images')) {
            $data['gallery_images'] = $product->images->map(fn ($img) => [
                'id' => $img->id,
                'url' => asset('storage/'.$img->image),
                'alt' => $product->name,
            ])->toArray();
        }

        if ($product->relationLoaded('category') && $product->category) {
            $data['category'] = [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug ?? null,
            ];
        }

        foreach ($this->customRelations as $relation) {
            if ($product->relationLoaded($relation)) {

                $key = $relation === 'base_cards' ? 'custom_sets' : $relation;

                $mapped = $product->{$relation}->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'image' => $item->image ? asset('storage/'.$item->image) : null,
                    'card_type' => $item->card_type ?? null,
                ])->toArray();

                $data['customizations'][$key] = $mapped;

                if ($relation === 'base_cards') {
                    $data['customizations']['base_cards'] = $mapped;
                }
            }
        }

        return $data;
    }

    /* ---------- Unified Response Helpers ---------- */

    private function successResponse(string $message, $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'status' => $status, 'message' => $message, 'data' => $data], $status);
    }

    private function errorResponse(string $message, int $status = 500): JsonResponse
    {
        return response()->json(['success' => false, 'status' => $status, 'message' => $message], $status);
    }

    private function validationErrorResponse(ValidationException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status' => 422,
            'message' => 'Validation failed',
            'errors' => $e->errors(),
        ], 422);
    }

    private function unauthorizedResponse(): JsonResponse
    {
        return $this->errorResponse('Unauthorized access', 401);
    }
}
