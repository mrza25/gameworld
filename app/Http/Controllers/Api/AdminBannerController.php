<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Managing the homepage banners.
 */
class AdminBannerController extends Controller
{
    /**
     * List all banners for admin.
     */
    public function banners(Request $request): JsonResponse
    {
        $banners = DB::table('banners')
            ->orderBy('sort_order')
            ->latest()
            ->get();

        return response()->json([
            'ok' => true,
            'data' => $banners,
        ]);
    }

    /**
     * Create a banner.
     */
    public function storeBanner(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'image' => $request->hasFile('image')
                ? ['required', 'image', 'max:4096']
                : ['required', 'string', 'max:2048'],
            'link' => ['nullable', 'string', 'max:500', 'regex:#^(/(?!/)|https?://)#'],
            'sort_order' => ['nullable', 'integer'],
        ], [
            'link.regex' => 'لینک باید با / یا http شروع شود.',
        ]);

        $imagePath = $request->hasFile('image')
            ? $request->file('image')->store('banners', 'public')
            : $validated['image'];

        Cache::forget(CommonController::BANNERS_CACHE_KEY);

        $id = DB::table('banners')->insertGetId([
            'title' => $validated['title'],
            'image' => $imagePath,
            'link' => $validated['link'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'بنر با موفقیت ثبت شد.',
            'id' => $id,
        ], 201);
    }

    /**
     * Delete a banner.
     */
    public function deleteBanner(int $id): JsonResponse
    {
        DB::table('banners')->where('id', $id)->delete();
        Cache::forget(CommonController::BANNERS_CACHE_KEY);

        return response()->json([
            'ok' => true,
            'message' => 'بنر با موفقیت حذف شد.',
        ]);
    }
}
