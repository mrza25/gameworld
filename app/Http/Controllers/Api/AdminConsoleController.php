<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminConsoleController extends Controller
{
    /**
     * List all consoles with how many game variants use each one.
     */
    public function index(): JsonResponse
    {
        $consoles = Platform::query()
            ->ordered()
            ->withCount('gameVariants')
            ->get()
            ->map(fn (Platform $platform) => $platform->toConsoleArray() + [
                'variants_count' => $platform->game_variants_count,
            ]);

        return response()->json([
            'ok' => true,
            'data' => $consoles,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->mergeConsoleFieldAliases($request);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100', 'unique:platforms,name'],
            'value' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:platforms,slug'],
            ...$this->displayRules(),
        ]);

        $platform = Platform::create([
            'name' => $validated['label'],
            'slug' => Str::lower($validated['value']),
            'short' => $validated['short'] ?? null,
            'brand' => isset($validated['brand']) ? Str::lower($validated['brand']) : null,
            'sort_order' => $validated['sort_order'] ?? ((int) Platform::max('sort_order') + 10),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'کنسول با موفقیت ایجاد شد.',
            'data' => $platform->toConsoleArray(),
        ], 201);
    }

    /**
     * Update a console. Renaming also updates the console name stored on
     * existing orders, so they stay linked to this console.
     */
    public function update(Request $request, Platform $platform): JsonResponse
    {
        $this->mergeConsoleFieldAliases($request);

        $validated = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('platforms', 'name')->ignore($platform->id)],
            'value' => ['sometimes', 'required', 'string', 'max:50', 'alpha_dash', Rule::unique('platforms', 'slug')->ignore($platform->id)],
            ...$this->displayRules(),
        ]);

        $newSlug = isset($validated['value']) ? Str::lower($validated['value']) : $platform->slug;

        if ($newSlug !== $platform->slug && $platform->gameVariants()->exists()) {
            return response()->json([
                'ok' => false,
                'error' => 'شناسه (value) کنسولی که در بازی‌ها استفاده شده قابل تغییر نیست.',
            ], 422);
        }

        DB::transaction(function () use ($platform, $validated, $newSlug) {
            $oldName = $platform->name;

            $platform->fill([
                'name' => $validated['label'] ?? $platform->name,
                'slug' => $newSlug,
                'short' => array_key_exists('short', $validated) ? $validated['short'] : $platform->short,
                'brand' => array_key_exists('brand', $validated) ? ($validated['brand'] ? Str::lower($validated['brand']) : null) : $platform->brand,
                'sort_order' => $validated['sort_order'] ?? $platform->sort_order,
            ])->save();

            if ($platform->name !== $oldName) {
                Order::where('console_type', $oldName)->update(['console_type' => $platform->name]);
            }
        });

        return response()->json([
            'ok' => true,
            'message' => 'کنسول با موفقیت ویرایش شد.',
            'data' => $platform->fresh()->toConsoleArray(),
        ]);
    }

    public function destroy(Platform $platform): JsonResponse
    {
        if ($platform->gameVariants()->exists()) {
            return response()->json([
                'ok' => false,
                'error' => 'این کنسول در بازی‌ها استفاده شده و قابل حذف نیست.',
            ], 422);
        }

        $platform->delete();

        return response()->json([
            'ok' => true,
            'message' => 'کنسول با موفقیت حذف شد.',
        ]);
    }

    /**
     * Accept the database field names (name, slug) as aliases for label and value.
     */
    private function mergeConsoleFieldAliases(Request $request): void
    {
        if (! $request->has('label') && $request->has('name')) {
            $request->merge(['label' => $request->input('name')]);
        }
        if (! $request->has('value') && $request->has('slug')) {
            $request->merge(['value' => $request->input('slug')]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function displayRules(): array
    {
        return [
            'short' => ['nullable', 'string', 'max:50'],
            'brand' => ['nullable', 'string', 'max:30', 'alpha_dash'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
