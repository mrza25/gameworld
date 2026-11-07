<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CouponRequest;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $coupons = Coupon::query()
            ->when($request->filled('search'), fn ($query) => $query->where('code', 'like', '%'.Coupon::normalizeCode((string) $request->input('search')).'%'))
            ->latest()
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return $this->paginatedResponse($coupons, $coupons->getCollection()->map(fn (Coupon $coupon) => $coupon->toApiArray()));
    }

    public function store(CouponRequest $request): JsonResponse
    {
        $coupon = Coupon::create($request->validated());

        return response()->json([
            'ok' => true,
            'message' => 'کد تخفیف ساخته شد.',
            'data' => $coupon->fresh()->toApiArray(),
        ], 201);
    }

    public function update(CouponRequest $request, Coupon $coupon): JsonResponse
    {
        $coupon->update($request->validated());

        return response()->json([
            'ok' => true,
            'message' => 'کد تخفیف به‌روز شد.',
            'data' => $coupon->fresh()->toApiArray(),
        ]);
    }

    /**
     * A coupon that was used stays for the records and can only be turned off.
     */
    public function destroy(Coupon $coupon): JsonResponse
    {
        if ($coupon->usages()->exists()) {
            return response()->json([
                'ok' => false,
                'error' => 'این کد تخفیف استفاده شده و قابل حذف نیست. می‌توانید آن را غیرفعال کنید.',
            ], 422);
        }

        $coupon->delete();

        return response()->json([
            'ok' => true,
            'message' => 'کد تخفیف حذف شد.',
        ]);
    }
}
