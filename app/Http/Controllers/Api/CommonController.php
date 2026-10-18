<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommonController extends Controller
{
    /**
     * Get active banners for home page.
     */
    public function banners(): JsonResponse
    {
        $banners = DB::table('banners')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->latest()
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'title' => $b->title,
                'image' => \Illuminate\Support\Str::startsWith($b->image, ['http://', 'https://'])
                    ? $b->image
                    : url('storage/' . ltrim($b->image, '/')),
                'link' => $b->link,
                'sort_order' => $b->sort_order,
            ]);

        return response()->json([
            'ok' => true,
            'data' => $banners,
        ]);
    }

    /**
     * Get current user's notifications.
     */
    public function notifications(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'ok' => true,
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'data' => $notifications,
        ]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markNotificationAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $id)
            ->firstOrFail();

        $notification->markAsRead();

        return response()->json([
            'ok' => true,
            'message' => 'اعلان به عنوان خوانده شده علامت‌گذاری شد.',
        ]);
    }

    /**
     * Mark all user notifications as read.
     */
    public function markAllNotificationsAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json([
            'ok' => true,
            'message' => 'تمام اعلان‌ها خوانده شدند.',
        ]);
    }

    /**
     * Submit rating and review for a game.
     */
    public function rateGame(Request $request, int $id): JsonResponse
    {
        $game = Game::findOrFail($id);

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::table('game_ratings')->updateOrInsert(
            [
                'game_id' => $game->id,
                'user_id' => $request->user()->id,
            ],
            [
                'rating' => $validated['rating'],
                'comment' => $validated['comment'] ?? null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $avgRating = DB::table('game_ratings')->where('game_id', $game->id)->avg('rating');
        $ratingCount = DB::table('game_ratings')->where('game_id', $game->id)->count();

        return response()->json([
            'ok' => true,
            'message' => 'امتیاز شما با موفقیت ثبت شد.',
            'average_rating' => round((float) $avgRating, 1),
            'rating_count' => $ratingCount,
        ]);
    }
}

