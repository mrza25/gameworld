<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameRating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommonController extends Controller
{
    public const BANNERS_CACHE_KEY = 'catalog:active-banners';

    /**
     * Get active banners for home page.
     */
    public function banners(): JsonResponse
    {
        $rows = Cache::remember(self::BANNERS_CACHE_KEY, now()->addHour(), fn () => DB::table('banners')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->latest()
            ->get()
            ->map(fn (object $row) => (array) $row)
            ->all());

        $banners = collect($rows)
            ->map(fn (array $banner) => [
                'id' => $banner['id'],
                'title' => $banner['title'],
                'image' => Str::startsWith($banner['image'], ['http://', 'https://'])
                    ? $banner['image']
                    : url('storage/'.ltrim($banner['image'], '/')),
                'link' => $banner['link'],
                'sort_order' => $banner['sort_order'],
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
            ->paginate(min(max($request->integer('per_page', 20), 1), 100))
            ->withQueryString();

        return response()->json([
            'ok' => true,
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'data' => $notifications,
        ] + $this->paginationDetails($notifications));
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
     * Submit rating and review for a game by an authenticated user.
     */
    public function rateGame(Request $request, string $id): JsonResponse
    {
        $game = Game::where('id', is_numeric($id) ? (int) $id : 0)
            ->orWhere('slug', $id)
            ->firstOrFail();

        $ratingVal = $request->input('rating') ?? $request->input('rate') ?? $request->input('score');

        if (! $ratingVal || ! is_numeric($ratingVal) || $ratingVal < 1 || $ratingVal > 5) {
            return response()->json([
                'ok' => false,
                'message' => 'امتیاز باید عددی بین ۱ تا ۵ باشد.',
                'errors' => [
                    'rating' => ['امتیاز باید عددی بین ۱ تا ۵ باشد.'],
                ],
            ], 422);
        }

        $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $ratingInt = (int) round((float) $ratingVal);

        GameRating::updateOrCreate(
            [
                'game_id' => $game->id,
                'user_id' => $request->user()->id,
            ],
            [
                'rating' => $ratingInt,
                'comment' => $request->input('comment'),
            ]
        );

        $avgRating = $game->ratings()->avg('rating');
        $ratingCount = $game->ratings()->count();

        $rate = round((float) $avgRating, 1);

        return response()->json([
            'ok' => true,
            'message' => 'امتیاز شما با موفقیت ثبت شد.',
            'userRating' => $ratingInt,
            'user_rating' => $ratingInt,
            'rate' => $rate,
            'average_rating' => $rate,
            'ratingCount' => $ratingCount,
            'rating_count' => $ratingCount,
            'game_id' => $game->id,
            'game_title' => $game->title,
        ]);
    }
}
