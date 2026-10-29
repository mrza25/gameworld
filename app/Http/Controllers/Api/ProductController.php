<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    /**
     * List available games with their platform variants.
     *
     * Returns the full list, or a page of it when `page` or `per_page` is given.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Game::query()
            ->withCatalogStats(auth('sanctum')->user())
            ->where('is_available', true);

        if ($request->filled('genre') && $request->genre !== 'all') {
            $genreInput = trim((string) $request->genre);
            $genreMap = [
                'action' => 'اکشن',
                'adventure' => 'ماجراجویی',
                'avanture' => 'ماجراجویی',
                'rpg' => 'نقش‌آفرینی',
                'horror' => 'ترسناک',
                'sports' => 'ورزشی',
                'racing' => 'مسابقه‌ای',
                'shooter' => 'شوتِر',
                'strategy' => 'استراتژی',
            ];
            $reverseMap = array_flip($genreMap);

            $searchValues = [$genreInput];
            if (isset($genreMap[strtolower($genreInput)])) {
                $searchValues[] = $genreMap[strtolower($genreInput)];
            }
            if (isset($reverseMap[$genreInput])) {
                $searchValues[] = $reverseMap[$genreInput];
            }

            $query->whereIn('genre', $searchValues);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('genre', 'like', "%{$search}%")
                    ->orWhere('developer', 'like', "%{$search}%");
            });
        }

        if ($request->filled('console')) {
            $consoleSlugs = array_filter(array_map('trim', explode(',', (string) $request->input('console'))));

            $query->whereHas('variants', fn ($variants) => $variants
                ->where('is_available', true)
                ->whereHas('platform', fn ($platform) => $platform->whereIn('slug', $consoleSlugs)));
        }

        if ($request->filled('brand')) {
            $query->where('developer', $request->input('brand'));
        }

        $minRating = (float) $request->input('min_rating', 0);
        if ($minRating > 0) {
            // Compared in tenths as an integer: PDO binds floats as strings, which SQLite never treats as >= a number.
            $query->whereRaw('(select avg(rating) from game_ratings where game_ratings.game_id = games.id) * 10 >= ?', [(int) round($minRating * 10)]);
        }

        match ($request->input('sort')) {
            'popular', 'sales' => $query->orderByDesc('sales'),
            'rating' => $query->orderByDesc('ratings_avg_rating')->orderByDesc('ratings_count'),
            'size' => $query->orderBy('size'),
            default => $query->latest(),
        };
        $query->orderByDesc('id');

        if ($request->hasAny(['page', 'per_page'])) {
            $games = $query->paginate(min(max($request->integer('per_page', 12), 1), 48))->withQueryString();

            return $this->paginatedResponse($games, $games->getCollection()->map(fn (Game $game) => $this->transformGame($game)));
        }

        return response()->json([
            'ok' => true,
            'data' => $query->get()->map(fn (Game $game) => $this->transformGame($game)),
        ]);
    }

    /**
     * List available game genres in Persian and slug.
     */
    public function genres(): JsonResponse
    {
        $genres = [
            ['id' => 1, 'name' => 'اکشن', 'slug' => 'action'],
            ['id' => 2, 'name' => 'ماجراجویی', 'slug' => 'adventure'],
            ['id' => 3, 'name' => 'نقش‌آفرینی', 'slug' => 'rpg'],
            ['id' => 4, 'name' => 'ترسناک', 'slug' => 'horror'],
            ['id' => 5, 'name' => 'ورزشی', 'slug' => 'sports'],
            ['id' => 6, 'name' => 'مسابقه‌ای', 'slug' => 'racing'],
            ['id' => 7, 'name' => 'شوتِر', 'slug' => 'shooter'],
            ['id' => 8, 'name' => 'استراتژی', 'slug' => 'strategy'],
            ['id' => 9, 'name' => 'جهان‌باز', 'slug' => 'open-world'],
        ];

        return response()->json([
            'ok' => true,
            'data' => $genres,
        ]);
    }

    /**
     * Get a single product by slug or id.
     */
    public function show(string $slug): JsonResponse
    {
        $game = Game::query()
            ->withCatalogStats(auth('sanctum')->user())
            ->where(fn ($query) => $query
                ->where('slug', $slug)
                ->orWhere('id', is_numeric($slug) ? $slug : 0))
            ->firstOrFail();

        $data = $this->transformGame($game);

        return response()->json([
            'ok' => true,
            'data' => $data,
            'game' => $data,
            'product' => $data,
        ]);
    }

    /**
     * List the 9 distinct console platforms and editions.
     */
    public function consoles(): JsonResponse
    {
        $consoles = Cache::remember(Platform::CONSOLES_CACHE_KEY, now()->addHour(), fn () => Platform::query()
            ->ordered()
            ->get()
            ->map(fn (Platform $platform) => $platform->toConsoleArray())
            ->all());

        return response()->json([
            'ok' => true,
            'data' => $consoles,
        ]);
    }

    private function transformGame(Game $game): array
    {
        return $game->toCatalogArray();
    }
}
