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

        return response()->json([
            'ok' => true,
            'data' => $this->transformGame($game),
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
        $avgRating = \Illuminate\Support\Facades\DB::table('game_ratings')->where('game_id', $game->id)->avg('rating');
        $ratingCount = \Illuminate\Support\Facades\DB::table('game_ratings')->where('game_id', $game->id)->count();

        $rate = $avgRating ? round((float) $avgRating, 1) : 4.8;
        $count = $ratingCount > 0 ? $ratingCount : 12;

        $userRating = null;
        $currentUser = auth('sanctum')->user();
        if ($currentUser) {
            $userRating = \Illuminate\Support\Facades\DB::table('game_ratings')
                ->where('game_id', $game->id)
                ->where('user_id', $currentUser->id)
                ->value('rating');
        }

        $sales = $game->sales ?? $game->orderItems()->count();

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
            'fighting' => 'مبارزه‌ای',
            'open world' => 'جهان‌باز',
        ];

        $rawGenre = (string) ($game->genre ?? 'اکشن');
        $persianGenre = $genreMap[strtolower(trim($rawGenre))] ?? $rawGenre;

        $developer = $game->developer ?: 'PlayStation Studios';
        $releaseDate = $game->release_date ? $game->release_date->format('Y-m-d') : '2023-10-20';
        $releaseDateJalali = $game->release_date ? \App\Helpers\Jalali::fromDateTime($game->release_date) : '۱۴۰۲/۰۷/۲۸';

        $defaultScreenshots = [
            url('storage/screenshots/gow-1.jpg'),
            url('storage/screenshots/gow-2.jpg'),
        ];

        $screenshots = [];
        if (!empty($game->screenshots) && is_array($game->screenshots)) {
            foreach ($game->screenshots as $img) {
                if (empty($img)) continue;
                $screenshots[] = \Illuminate\Support\Str::startsWith($img, ['http://', 'https://'])
                    ? $img
                    : url(ltrim($img, '/'));
            }
        }

        if (empty($screenshots)) {
            $screenshots = $defaultScreenshots;
        }

        return [
            'id' => $game->id,
            'name' => $game->title,
            'title' => $game->title,
            'slug' => $game->slug,
            'genre' => $persianGenre,
            'genre_raw' => $rawGenre,
            'genreRaw' => $rawGenre,
            'brand' => $developer,
            'developer' => $developer,
            'releaseDate' => $releaseDate,
            'release_date' => $releaseDate,
            'releaseDateJalali' => $releaseDateJalali,
            'age' => $game->age_rating ?? 16,
            'available' => (bool) $game->is_available,
            'description' => $game->description,
            'rate' => (float) $rate,
            'ratingCount' => (int) $count,
            'userRating' => $userRating ? (int) $userRating : null,
            'user_rating' => $userRating ? (int) $userRating : null,
            'sales' => (int) $sales,
            'modes' => $game->modes ?? ['تک‌نفره', 'آنلاین'],
            'languages' => $game->languages ?? ['انگلیسی', 'زیرنویس فارسی'],
            'image' => $game->image
                ? (\Illuminate\Support\Str::startsWith($game->image, ['http://', 'https://']) ? $game->image : url('storage/' . ltrim($game->image, '/')))
                : url('/images/logosite.png'),
            'screenshots' => $screenshots,
            'platforms' => $game->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'variant_id' => $variant->id,
                'console' => $variant->platform->slug,
                'console_name' => $variant->platform->name,
                'size' => (float) $variant->size,
                'price' => (float) $variant->price,
                'is_available' => (bool) $variant->is_available,
            ])->values()->all(),
        ];
    }
}

