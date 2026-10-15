<?php

namespace App\Http\Controllers;

use App\Models\GameVariant;
use App\Models\Order;
use App\Models\Platform;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index()
    {
        $orders = Auth::user()
            ->orders()
            ->with('items.game', 'items.gameVariant.platform')
            ->latest()
            ->get();

        return view('orders.index', compact('orders'));
    }

    public function create()
    {
        $platforms = Platform::orderBy('id')->get();

        $variants = GameVariant::query()
            ->with(['game', 'platform'])
            ->where('is_available', true)
            ->whereHas('game')
            ->whereHas('platform')
            ->orderBy('platform_id')
            ->orderBy('game_id')
            ->get();

        return view('orders.create', compact('variants', 'platforms'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'console_type' => [
                'required',
                'string',
                Rule::exists('platforms', 'name'),
            ],

            'variants' => [
                'required',
                'array',
                'min:1',
            ],

            'variants.*' => [
                'required',
                'integer',
                'distinct',
                'exists:game_variants,id',
            ],
        ]);

        $variants = GameVariant::query()
            ->with(['game', 'platform'])
            ->whereIn('id', $validated['variants'])
            ->where('is_available', true)
            ->get();

        if ($variants->count() !== count($validated['variants'])) {
            throw ValidationException::withMessages([
                'variants' => 'یکی از نسخه‌های انتخاب‌شده موجود نیست.',
            ]);
        }

        $selectedPlatformSlug = Str::slug($validated['console_type']);

        foreach ($variants as $variant) {
            if ($variant->platform->slug !== $selectedPlatformSlug) {
                throw ValidationException::withMessages([
                    'variants' => 'پلتفرم یکی از بازی‌های انتخاب‌شده با کنسول انتخابی مطابقت ندارد.',
                ]);
            }
        }

        // در این مرحله سفارشی ساخته نمی‌شود.
        // فقط انتخاب کاربر را برای مرحله Checkout نگه می‌داریم.
        $request->session()->put('checkout', [
            'console_type' => $validated['console_type'],
            'variants' => $variants->pluck('id')->all(),
        ]);

        return redirect()->route('checkout.index');
    }

    public function show(Order $order)
    {
        abort_unless(
            $order->user_id === Auth::id() || Auth::user()?->is_admin,
            403
        );

        $order->load('items.game', 'items.gameVariant.platform');

        return view('orders.show', compact('order'));
    }
}

