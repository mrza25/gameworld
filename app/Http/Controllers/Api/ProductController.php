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
            $query->where('genre', $request->genre);
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

        $sales = $game->sales ?? $game->orderItems()->count();

        $defaultScreenshots = [
            url('images/games/' . $game->slug . '-1.jpg'),
            url('images/games/' . $game->slug . '-2.jpg'),
        ];

        $screenshots = !empty($game->screenshots)
            ? array_map(fn ($img) => \Illuminate\Support\Str::startsWith($img, ['http://', 'https://']) ? $img : url('storage/' . ltrim($img, '/')), (array) $game->screenshots)
            : $defaultScreenshots;

        return [
            'id' => $game->id,
            'name' => $game->title,
            'title' => $game->title,
            'slug' => $game->slug,
            'genre' => $game->genre,
            'brand' => $game->developer ?? 'PlayStation',
            'developer' => $game->developer,
            'releaseDate' => $game->release_date?->format('Y-m-d'),
            'age' => $game->age_rating ?? 16,
            'available' => (bool) $game->is_available,
            'description' => $game->description,
            'rate' => (float) $rate,
            'ratingCount' => (int) $count,
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

