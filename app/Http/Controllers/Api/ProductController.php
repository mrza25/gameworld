<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * List all available products/games with their platform variants.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Game::query()
            ->with(['variants.platform'])
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
                  ->orWhere('genre', 'like', "%{$search}%")
                  ->orWhere('developer', 'like', "%{$search}%");
            });
        }

        if (in_array($request->input('sort'), ['popular', 'sales'], true)) {
            $query->orderByDesc('sales');
        } else {
            $query->latest();
        }

        $games = $query->get();

        $data = $games->map(fn (Game $game) => $this->transformGame($game));

        return response()->json([
            'ok' => true,
            'data' => $data,
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
            ->with(['variants.platform'])
            ->where('slug', $slug)
            ->orWhere('id', is_numeric($slug) ? $slug : 0)
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
        $consoles = Platform::orderBy('id')->get()->map(fn (Platform $platform) => [
            'id' => $platform->id,
            'label' => $platform->name,
            'value' => $platform->slug,
            'short' => $platform->name,
        ]);

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

