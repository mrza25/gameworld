<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\OrderItem;
use App\Models\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Managing the game catalog, its variants and their price options.
 */
class AdminGameController extends Controller
{
    /**
     * List games for administrative management.
     */
    public function games(Request $request): JsonResponse
    {
        $query = Game::query()->withCatalogStats()->pinnedFirst()->latest();

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($sq) use ($q) {
                $sq->where('title', 'like', "%{$q}%")
                    ->orWhere('genre', 'like', "%{$q}%")
                    ->orWhere('developer', 'like', "%{$q}%");
            });
        }

        if ($request->filled('genre')) {
            $query->where('genre', $request->genre);
        }

        if (! $request->filled('status') && $request->filled('is_available')) {
            $request->merge(['status' => $request->input('is_available')]);
        }

        if ($request->filled('status')) {
            $st = strtolower((string) $request->status);
            if ($st === 'available' || $st === '1' || $st === 'true') {
                $query->where('is_available', true);
            } elseif ($st === 'unavailable' || $st === '0' || $st === 'false') {
                $query->where('is_available', false);
            }
        }

        if ($request->filled('console')) {
            $consoleSlug = $request->console;
            $query->whereHas('variants.platform', function ($pq) use ($consoleSlug) {
                $pq->where('slug', $consoleSlug)->orWhere('name', $consoleSlug);
            });
        }

        $games = $query->paginate($this->perPage($request));
        $games->getCollection()->transform(fn (Game $game) => $game->toCatalogArray());

        $paginatorArray = $games->toArray();
        $pagination = $this->paginationDetails($games);
        $paginatorArray['meta'] = $pagination['meta'];

        return response()->json([
            'ok' => true,
            'data' => $paginatorArray,
            'games' => $games->items(),
        ] + $pagination);
    }

    /**
     * Get single game details for admin.
     */
    public function gameDetail(int|string $id): JsonResponse
    {
        $game = Game::with(['variants.platform', 'variants.activeOptions'])
            ->where('id', is_numeric($id) ? (int) $id : 0)
            ->orWhere('slug', (string) $id)
            ->firstOrFail();

        $data = $game->toCatalogArray();

        return response()->json([
            'ok' => true,
            'data' => $data,
            'game' => $data,
            'product' => $data,
        ]);
    }

    /**
     * Add a game with platform variants.
     */
    public function storeGame(Request $request): JsonResponse
    {
        $this->mergeGameFieldAliases($request);

        $modes = $this->decodeListInput($request->input('modes')) ?: ['تک‌نفره', 'آنلاین'];
        $languages = $this->decodeListInput($request->input('languages')) ?: ['انگلیسی', 'زیرنویس فارسی'];

        $rawVariants = $this->rawVariantsInput($request) ?? [];
        $firstVariant = is_array($rawVariants[0] ?? null) ? $rawVariants[0] : [];

        $basePrice = (float) (
            $this->firstOptionPrice($firstVariant)
            ?? $request->input('price')
            ?? $request->input('base_price')
            ?? $request->input('basePrice')
            ?? $request->input('amount')
            ?? $firstVariant['price']
            ?? 1000000
        );

        $baseSize = (float) (
            $request->input('size')
            ?? $request->input('file_size')
            ?? $request->input('fileSize')
            ?? $firstVariant['size']
            ?? 50.0
        );

        $request->merge([
            'variants' => empty($rawVariants)
                ? $this->defaultVariantsForAllPlatforms($basePrice, $baseSize)
                : $this->normalizeVariants($rawVariants, $basePrice, $baseSize),
        ]);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:games,slug'],
            'genre' => ['required', 'string', 'max:100'],
            'developer' => ['nullable', 'string', 'max:100'],
            'release_date' => ['nullable', 'date'],
            'releaseDate' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'age_rating' => ['nullable', 'integer', 'min:3', 'max:21'],
            'is_available' => ['nullable', 'boolean'],
            'is_pinned' => ['nullable', 'boolean'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.platform_id' => ['required', 'distinct', 'exists:platforms,id'],
            'variants.*.price' => ['required', 'numeric', 'min:0'],
            'variants.*.size' => ['required', 'numeric', 'min:0'],
            'variants.*.is_available' => ['nullable', 'boolean'],
            ...$this->gameImageRules($request),
            ...$this->variantOptionRules($request),
        ], $this->variantOptionMessages());

        $explicitPlatform = $request->input('platform') ?? $request->input('console');
        $basePlatform = is_string($explicitPlatform) && $explicitPlatform !== ''
            ? $explicitPlatform
            : (Platform::find($validated['variants'][0]['platform_id'])?->name ?? 'PlayStation 5');

        $imagePath = $this->resolveGameImageInput($request);

        $screenshots = $this->normalizeImageList($request->input('screenshots'));
        if ($request->hasFile('screenshots')) {
            foreach ($request->file('screenshots') as $file) {
                $screenshots[] = $file->store('screenshots', 'public');
            }
        }

        $slug = $validated['slug'] ?? Str::slug($validated['title']);
        if (Game::where('slug', $slug)->exists()) {
            $slug .= '-'.Str::random(4);
        }

        $releaseDate = $validated['release_date'] ?? $validated['releaseDate'] ?? now()->format('Y-m-d');

        $game = DB::transaction(function () use ($validated, $slug, $basePrice, $baseSize, $basePlatform, $releaseDate, $modes, $languages, $imagePath, $screenshots) {
            $game = Game::create([
                'title' => $validated['title'],
                'slug' => $slug,
                'genre' => $validated['genre'],
                'price' => $basePrice,
                'size' => $baseSize,
                'platform' => $basePlatform,
                'developer' => $validated['developer'] ?? 'PlayStation Studios',
                'release_date' => $releaseDate,
                'description' => $validated['description'] ?? null,
                'age_rating' => $validated['age_rating'] ?? 16,
                'modes' => $modes,
                'languages' => $languages,
                'image' => $imagePath,
                'screenshots' => $screenshots,
                'is_available' => $validated['is_available'] ?? true,
            ]);

            foreach ($validated['variants'] as $v) {
                $variant = $game->variants()->create([
                    'platform_id' => $v['platform_id'],
                    'price' => $v['price'],
                    'size' => $v['size'],
                    'is_available' => $v['is_available'] ?? true,
                ]);

                if (! empty($v['options'])) {
                    $variant->syncOptions($v['options']);
                    $variant->syncPriceWithDefaultOption();
                }
            }

            $game->setPinned((bool) ($validated['is_pinned'] ?? false));
            $game->save();

            return $game;
        });

        $catalogData = $game->fresh(['variants.platform', 'variants.activeOptions'])->toCatalogArray();

        return response()->json([
            'ok' => true,
            'message' => 'بازی با موفقیت ایجاد شد.',
            'data' => $catalogData,
            'game' => $catalogData,
            'product' => $catalogData,
        ], 201);
    }

    /**
     * Update a game and its platform variants.
     *
     * When variants are sent, they replace the game's variant list: listed
     * platforms are upserted and the others are deleted, or only marked
     * unavailable when existing orders reference them.
     */
    public function updateGame(Request $request, int|string $id): JsonResponse
    {
        $game = Game::with(['variants.platform', 'variants.activeOptions'])
            ->where('id', is_numeric($id) ? (int) $id : 0)
            ->orWhere('slug', (string) $id)
            ->firstOrFail();

        $this->mergeGameFieldAliases($request);

        $rawVariants = $this->rawVariantsInput($request);
        $firstVariant = is_array($rawVariants[0] ?? null) ? $rawVariants[0] : [];

        $basePrice = (float) (
            $this->firstOptionPrice($firstVariant)
            ?? ($request->filled('price') ? $request->input('price') : null)
            ?? $request->input('base_price')
            ?? $request->input('basePrice')
            ?? $firstVariant['price']
            ?? $game->price
        );

        $baseSize = $request->filled('size')
            ? (float) $request->input('size')
            : (float) ($request->input('file_size') ?? $request->input('fileSize') ?? $firstVariant['size'] ?? $game->size);

        $request->merge([
            'variants' => empty($rawVariants) ? null : $this->normalizeVariants($rawVariants, $basePrice, $baseSize, $game),
        ]);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('games', 'slug')->ignore($game->id)],
            'genre' => ['nullable', 'string', 'max:100'],
            'developer' => ['nullable', 'string', 'max:100'],
            'release_date' => ['nullable', 'date'],
            'releaseDate' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'age_rating' => ['nullable', 'integer', 'min:3', 'max:21'],
            'is_available' => ['nullable', 'boolean'],
            'is_pinned' => ['nullable', 'boolean'],
            'variants' => ['nullable', 'array'],
            'variants.*.platform_id' => ['required', 'distinct', 'exists:platforms,id'],
            'variants.*.price' => ['required', 'numeric', 'min:0'],
            'variants.*.size' => ['required', 'numeric', 'min:0'],
            'variants.*.is_available' => ['nullable', 'boolean'],
            ...$this->gameImageRules($request),
            ...$this->variantOptionRules($request, $game),
        ], $this->variantOptionMessages());

        $obsoleteImage = null;
        $newImage = $this->resolveGameImageInput($request);
        if ($newImage !== null) {
            $obsoleteImage = $game->image;
            $game->image = $newImage;
        } elseif ($request->boolean('remove_image')) {
            $obsoleteImage = $game->image;
            $game->image = null;
        }

        $screenshotListSent = $request->has('existing_screenshots') || $request->input('screenshots') !== null;
        if ($screenshotListSent || $request->hasFile('screenshots')) {
            $screenshots = match (true) {
                $request->has('existing_screenshots') => $this->normalizeImageList($request->input('existing_screenshots')),
                $request->input('screenshots') !== null => $this->normalizeImageList($request->input('screenshots')),
                default => (array) ($game->screenshots ?? []),
            };

            foreach ($request->hasFile('screenshots') ? $request->file('screenshots') : [] as $file) {
                $screenshots[] = $file->store('screenshots', 'public');
            }

            $game->screenshots = array_values($screenshots);
        }

        if (! empty($validated['slug'])) {
            $game->slug = $validated['slug'];
        }
        if (isset($validated['title'])) {
            $game->title = $validated['title'];
        }
        if (isset($validated['genre'])) {
            $game->genre = $validated['genre'];
        }
        if (isset($validated['developer'])) {
            $game->developer = $validated['developer'];
        }
        if (isset($validated['release_date'])) {
            $game->release_date = $validated['release_date'];
        }
        if (isset($validated['releaseDate'])) {
            $game->release_date = $validated['releaseDate'];
        }
        if (isset($validated['description'])) {
            $game->description = $validated['description'];
        }
        if (isset($validated['age_rating'])) {
            $game->age_rating = $validated['age_rating'];
        }
        if (isset($validated['is_available'])) {
            $game->is_available = $validated['is_available'];
        }
        if (isset($validated['is_pinned'])) {
            $game->setPinned((bool) $validated['is_pinned']);
        }

        if ($request->has('price') || $basePrice > 0) {
            $game->price = $basePrice;
        }
        if ($request->has('size') || $baseSize > 0) {
            $game->size = $baseSize;
        }
        if ($request->filled('platform') || $request->filled('console')) {
            $game->platform = $request->input('platform') ?? $request->input('console');
        }

        if ($request->has('modes')) {
            $game->modes = $this->decodeListInput($request->input('modes'));
        }

        if ($request->has('languages')) {
            $game->languages = $this->decodeListInput($request->input('languages'));
        }

        DB::transaction(function () use ($game, $validated, $request) {
            $game->save();

            if (! empty($validated['variants'])) {
                $keptPlatformIds = [];

                foreach ($validated['variants'] as $v) {
                    $variant = $game->variants()->updateOrCreate(
                        ['platform_id' => $v['platform_id']],
                        [
                            'price' => $v['price'],
                            'size' => $v['size'],
                            'is_available' => $v['is_available'] ?? true,
                        ]
                    );

                    // A missing `options` field leaves them as they are; an empty list removes them.
                    if (isset($v['options'])) {
                        $variant->syncOptions($v['options']);
                    }

                    $variant->syncPriceWithDefaultOption();

                    $keptPlatformIds[] = (int) $v['platform_id'];
                }

                $this->retireRemovedVariants($game, $keptPlatformIds);
            } elseif ($request->has('price') || $request->has('size')) {
                // Sync updated price/size to all existing variants if no specific variants passed
                foreach ($game->variants as $variant) {
                    $isCopy = str_contains($variant->platform?->slug ?? '', 'copy') || ($variant->platform?->slug === 'other');
                    $price = $isCopy ? round($game->price * 0.35) : $game->price;
                    $variant->update([
                        // A variant with options takes its price from them, not from the game.
                        'price' => $variant->activeOptions->isEmpty() ? $price : $variant->price,
                        'size' => $game->size > 0 ? $game->size : $variant->size,
                    ]);
                }
            }
        });

        $this->deleteUploadedGameImage($obsoleteImage, $game->image);

        $catalogData = $game->fresh(['variants.platform', 'variants.activeOptions'])->toCatalogArray();

        return response()->json([
            'ok' => true,
            'message' => 'بازی با موفقیت به‌روزرسانی شد.',
            'data' => $catalogData,
            'game' => $catalogData,
            'product' => $catalogData,
        ]);
    }

    /**
     * Pin a game to the top of the game lists, or unpin it.
     */
    public function pinGame(Request $request, int|string $id): JsonResponse
    {
        $game = Game::where('id', is_numeric($id) ? (int) $id : 0)
            ->orWhere('slug', (string) $id)
            ->firstOrFail();

        $request->merge(['is_pinned' => $this->toBooleanOrOriginal($request->input('is_pinned'))]);

        $validated = $request->validate([
            'is_pinned' => ['required', 'boolean'],
        ]);

        $game->setPinned((bool) $validated['is_pinned']);
        $game->save();

        $catalogData = $game->fresh(['variants.platform', 'variants.activeOptions'])->toCatalogArray();

        return response()->json([
            'ok' => true,
            'message' => $game->is_pinned ? 'بازی پین شد.' : 'پین بازی برداشته شد.',
            'data' => $catalogData,
            'game' => $catalogData,
            'product' => $catalogData,
        ]);
    }

    /**
     * Upload screenshots for a game.
     */
    public function uploadScreenshots(Request $request, int|string $id): JsonResponse
    {
        $game = Game::where('id', is_numeric($id) ? (int) $id : 0)
            ->orWhere('slug', (string) $id)
            ->firstOrFail();

        $request->validate([
            'screenshots' => ['required', 'array'],
            'screenshots.*' => ['image', 'max:4096'],
        ]);

        $currentScreenshots = (array) ($game->screenshots ?? []);
        foreach ($request->file('screenshots') as $file) {
            $currentScreenshots[] = $file->store('screenshots', 'public');
        }

        $game->update(['screenshots' => $currentScreenshots]);

        return response()->json([
            'ok' => true,
            'message' => 'اسکرین‌شات‌ها با موفقیت ذخیره شدند.',
            'screenshots' => array_map(fn (string $path) => Game::publicImageUrl($path), $currentScreenshots),
        ]);
    }

    /**
     * Delete a game.
     */
    public function deleteGame(int|string $id): JsonResponse
    {
        $game = Game::where('id', is_numeric($id) ? (int) $id : 0)
            ->orWhere('slug', (string) $id)
            ->firstOrFail();

        if ($game->orderItems()->exists()) {
            return response()->json([
                'ok' => false,
                'error' => 'به دلیل وجود در سفارشات قبلی، امکان حذف این بازی وجود ندارد.',
            ], 422);
        }

        DB::transaction(function () use ($game) {
            $game->variants()->delete();
            $game->delete();
        });

        return response()->json([
            'ok' => true,
            'message' => 'بازی با موفقیت حذف شد.',
        ]);
    }

    /**
     * Map the alternative field names the frontend may send onto the canonical ones.
     */
    private function mergeGameFieldAliases(Request $request): void
    {
        if (! $request->filled('title') && $request->filled('name')) {
            $request->merge(['title' => $request->input('name')]);
        }
        if (! $request->filled('developer') && $request->filled('brand')) {
            $request->merge(['developer' => $request->input('brand')]);
        }
        if (! $request->filled('age_rating') && $request->filled('age')) {
            $request->merge(['age_rating' => (int) $request->input('age')]);
        }
        if (! $request->filled('release_date') && $request->filled('releaseDate')) {
            $request->merge(['release_date' => $request->input('releaseDate')]);
        }
        if (! $request->has('is_available') && $request->has('available')) {
            $request->merge(['is_available' => $request->input('available')]);
        }

        $isAvailable = $this->toBooleanOrOriginal($request->input('is_available'));
        if ($isAvailable !== null) {
            $request->merge(['is_available' => $isAvailable]);
        }

        $isPinned = $this->toBooleanOrOriginal($request->input('is_pinned'));
        if ($isPinned !== null) {
            $request->merge(['is_pinned' => $isPinned]);
        }
    }

    /**
     * Normalize a variant's `options` before validation. They may be an array or
     * a JSON string (multipart FormData). Anything that is not a list is kept
     * as-is so that validation reports it.
     */
    private function normalizeVariantOptions(mixed $options): mixed
    {
        $rows = $this->decodeListInput($options);

        if ($rows === null) {
            return $options;
        }

        return array_map(function (mixed $row): array {
            $label = is_array($row) ? ($row['label'] ?? null) : $row;
            $label = is_string($label) ? trim($label) : $label;

            return [
                'id' => is_array($row) ? ($row['id'] ?? null) : null,
                'label' => $label === '' ? null : $label,
                'price' => is_array($row) ? ($row['price'] ?? null) : null,
            ];
        }, $rows);
    }

    /**
     * The price of a variant input's first option, if it has one. That option is
     * the default, so its price is the variant's price.
     *
     * @param  array<string, mixed>  $variant
     */
    private function firstOptionPrice(array $variant): mixed
    {
        $options = $this->decodeListInput($variant['options'] ?? null);
        $price = is_array($options[0] ?? null) ? ($options[0]['price'] ?? null) : null;

        return is_numeric($price) ? $price : null;
    }

    /**
     * Rules for each variant's options. They are built per variant because
     * `distinct` compares across its whole wildcard: on `variants.*.options.*`
     * two consoles could not both have a row called "ظرفیت ۱".
     *
     * @return array<string, array<int, mixed>>
     */
    private function variantOptionRules(Request $request, ?Game $game = null): array
    {
        $rules = [];

        foreach ((array) $request->input('variants') as $index => $variant) {
            if (! is_array($variant) || ! array_key_exists('options', $variant)) {
                continue;
            }

            $existingVariant = $game?->variants->firstWhere('platform_id', $variant['platform_id'] ?? null);
            $hasSeveralRows = is_array($variant['options']) && count($variant['options']) > 1;

            $rules["variants.{$index}.options"] = ['array', 'max:6'];
            $rules["variants.{$index}.options.*.id"] = ['nullable', 'integer', 'distinct', Rule::exists('game_variant_options', 'id')->where('game_variant_id', $existingVariant?->id ?? 0)];
            $rules["variants.{$index}.options.*.label"] = [$hasSeveralRows ? 'required' : 'nullable', 'string', 'max:100', 'distinct:ignore_case'];
            $rules["variants.{$index}.options.*.price"] = ['required', 'numeric', 'gt:0', 'max:99999999.99'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    private function variantOptionMessages(): array
    {
        return [
            'variants.*.options.array' => 'گزینه‌های قیمت باید به صورت لیست ارسال شوند.',
            'variants.*.options.max' => 'هر کنسول حداکثر ۶ گزینه‌ی قیمت می‌تواند داشته باشد.',
            'variants.*.options.*.id.integer' => 'شناسه‌ی گزینه معتبر نیست.',
            'variants.*.options.*.id.exists' => 'این گزینه متعلق به این کنسول نیست.',
            'variants.*.options.*.id.distinct' => 'یک گزینه دو بار ارسال شده است.',
            'variants.*.options.*.label.required' => 'وقتی بیش از یک ردیف قیمت هست، توضیح هر ردیف الزامی است.',
            'variants.*.options.*.label.string' => 'توضیح گزینه باید متن باشد.',
            'variants.*.options.*.label.max' => 'توضیح گزینه حداکثر ۱۰۰ کاراکتر است.',
            'variants.*.options.*.label.distinct' => 'توضیح ردیف‌های یک کنسول نباید تکراری باشد.',
            'variants.*.options.*.price.required' => 'قیمت گزینه الزامی است.',
            'variants.*.options.*.price.numeric' => 'قیمت گزینه باید عدد باشد.',
            'variants.*.options.*.price.gt' => 'قیمت گزینه باید بزرگ‌تر از صفر باشد.',
            'variants.*.options.*.price.max' => 'قیمت گزینه بیش از حد مجاز است.',
        ];
    }

    /**
     * Read the variants list from any supported input shape (array, JSON string,
     * or a single platform/console value).
     *
     * @return array<int, mixed>|null
     */
    private function rawVariantsInput(Request $request): ?array
    {
        $rawVariants = $request->input('variants') ?? $request->input('platforms');

        if (empty($rawVariants) && $request->filled('platform')) {
            $rawVariants = [$request->input('platform')];
        }
        if (empty($rawVariants) && $request->filled('console')) {
            $rawVariants = [$request->input('console')];
        }

        return $this->decodeListInput($rawVariants);
    }

    /**
     * Decode list input that may arrive as an array, a JSON string (multipart
     * FormData) or a comma-separated string.
     *
     * @return array<int, mixed>|null
     */
    private function decodeListInput(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_validate($value) ? json_decode($value, true) : array_map('trim', explode(',', $value));
        }

        if (! is_array($value)) {
            return null;
        }

        return array_values(array_filter($value, fn ($item) => $item !== null && $item !== '' && $item !== []));
    }

    /**
     * Turn variant input into rows for validation. Platforms may be referenced
     * by id, slug or name; unresolvable references are kept as-is so that
     * validation reports them instead of silently dropping them.
     *
     * @param  array<int, mixed>  $rawVariants
     * @return list<array{platform_id: mixed, price: mixed, size: mixed, is_available: mixed, options?: mixed}>
     */
    private function normalizeVariants(array $rawVariants, float $basePrice, float $baseSize, ?Game $game = null): array
    {
        $normalized = [];

        foreach ($rawVariants as $variant) {
            if (is_array($variant)) {
                $reference = $variant['platform_id'] ?? $variant['console'] ?? $variant['platform'] ?? $variant['slug'] ?? null;
                $platform = $this->resolvePlatform($reference);

                $row = [
                    'platform_id' => $platform?->id ?? (is_scalar($reference) ? $reference : null),
                    'price' => $variant['price'] ?? $basePrice,
                    'size' => $variant['size'] ?? $baseSize,
                    'is_available' => $this->toBooleanOrOriginal($variant['is_available'] ?? $variant['available'] ?? true),
                ];

                if (isset($variant['options'])) {
                    $row['options'] = $this->normalizeVariantOptions($variant['options']);

                    // With options the price comes from the first one. Removing them all keeps the last price.
                    $row['price'] = $this->firstOptionPrice($row)
                        ?? $variant['price']
                        ?? $game?->variants->firstWhere('platform_id', $row['platform_id'])?->price
                        ?? $basePrice;
                }

                $normalized[] = $row;

                continue;
            }

            $platform = $this->resolvePlatform($variant);
            $isCopy = $platform && (str_contains($platform->slug, 'copy') || $platform->slug === 'other');

            $normalized[] = [
                'platform_id' => $platform?->id ?? $variant,
                'price' => $isCopy ? round($basePrice * 0.35) : $basePrice,
                'size' => $baseSize,
                'is_available' => true,
            ];
        }

        return $normalized;
    }

    /**
     * @return list<array{platform_id: int, price: float, size: float, is_available: bool}>
     */
    private function defaultVariantsForAllPlatforms(float $basePrice, float $baseSize): array
    {
        return Platform::all()->map(fn (Platform $platform) => [
            'platform_id' => $platform->id,
            'price' => (str_contains($platform->slug, 'copy') || $platform->slug === 'other') ? round($basePrice * 0.35) : $basePrice,
            'size' => $baseSize,
            'is_available' => true,
        ])->all();
    }

    private function resolvePlatform(mixed $reference): ?Platform
    {
        if (is_array($reference)) {
            $reference = $reference['slug'] ?? $reference['id'] ?? null;
        }

        if (is_int($reference) || (is_string($reference) && ctype_digit($reference))) {
            return Platform::find((int) $reference);
        }

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        return Platform::where('slug', $reference)->orWhere('name', $reference)->first();
    }

    private function toBooleanOrOriginal(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value;
    }

    /**
     * Validation rules for cover and screenshot inputs, which may be uploaded
     * files or existing image references.
     *
     * @return array<string, array<int, string>>
     */
    private function gameImageRules(Request $request): array
    {
        $rules = [];

        foreach (['image', 'cover', 'poster'] as $field) {
            $rules[$field] = $request->hasFile($field)
                ? ['image', 'max:4096']
                : ['nullable', 'string', 'max:2048'];
        }

        $rules['screenshots'] = ['nullable'];
        $rules['screenshots.*'] = $request->hasFile('screenshots')
            ? ['image', 'max:4096']
            : ['nullable', 'string', 'max:2048'];
        $rules['existing_screenshots'] = ['nullable'];
        $rules['remove_image'] = ['nullable', 'boolean'];

        return $rules;
    }

    /**
     * Store an uploaded cover, or normalize a cover reference sent as a string.
     */
    private function resolveGameImageInput(Request $request): ?string
    {
        foreach (['image', 'cover', 'poster'] as $field) {
            if ($request->hasFile($field)) {
                return $request->file($field)->store('games', 'public');
            }
        }

        foreach (['image', 'cover', 'poster'] as $field) {
            $value = $request->input($field);
            if (is_string($value) && $value !== '') {
                return Game::normalizeImageReference($value);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function normalizeImageList(mixed $value): array
    {
        return collect($this->decodeListInput($value) ?? [])
            ->filter(fn ($reference) => is_string($reference) && $reference !== '')
            ->map(fn (string $reference) => Game::normalizeImageReference($reference))
            ->values()
            ->all();
    }

    /**
     * Delete a replaced cover file. Only files uploaded through the admin
     * panel (stored under "games/") are removed; seeded or shared images stay.
     */
    private function deleteUploadedGameImage(?string $reference, ?string $currentImage): void
    {
        if ($reference === null || $reference === $currentImage) {
            return;
        }

        $path = Game::normalizeImageReference($reference);

        if (Str::startsWith($path, 'games/') && ! Str::contains($path, '..')) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Remove variants that are no longer listed. Variants referenced by orders
     * are kept for order history and only marked unavailable.
     *
     * @param  list<int>  $keptPlatformIds
     */
    private function retireRemovedVariants(Game $game, array $keptPlatformIds): void
    {
        $removedVariants = $game->variants()->whereNotIn('platform_id', $keptPlatformIds)->get();

        if ($removedVariants->isEmpty()) {
            return;
        }

        $orderedVariantIds = OrderItem::query()
            ->whereIn('game_variant_id', $removedVariants->modelKeys())
            ->distinct()
            ->pluck('game_variant_id')
            ->all();

        foreach ($removedVariants as $variant) {
            if (in_array($variant->id, $orderedVariantIds, true)) {
                $variant->update(['is_available' => false]);
            } else {
                $variant->delete();
            }
        }
    }
}
