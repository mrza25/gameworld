<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $games = Game::query()
            ->withCatalogStats($user)
            ->whereIn('id', $user->wishlistGames()->allRelatedIds())
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 24))
            ->withQueryString();

        return $this->paginatedResponse($games, $games->getCollection()->map(fn (Game $game) => $game->toCatalogArray()));
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $request->user()->wishlistGames()->syncWithoutDetaching([$this->findGame($id)->id]);

        return response()->json([
            'ok' => true,
            'message' => 'به علاقه‌مندی‌ها اضافه شد.',
            'in_wishlist' => true,
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->user()->wishlistGames()->detach($this->findGame($id)->id);

        return response()->json([
            'ok' => true,
            'message' => 'از علاقه‌مندی‌ها حذف شد.',
            'in_wishlist' => false,
        ]);
    }

    private function findGame(string $id): Game
    {
        return Game::query()
            ->where('id', is_numeric($id) ? (int) $id : 0)
            ->orWhere('slug', $id)
            ->firstOrFail();
    }
}
