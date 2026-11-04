<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class GameVariant extends Model
{
    protected $fillable = [
        'game_id',
        'platform_id',
        'price',
        'size',
        'is_available',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'size' => 'decimal:2',
            'is_available' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(GameVariantOption::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The rows a customer can choose from. The first one is the default.
     */
    public function activeOptions(): HasMany
    {
        return $this->options()->where('is_active', true);
    }

    /**
     * Replace the variant's options with the given list, in that order.
     *
     * An option with an id is edited. One without an id takes over an unlisted
     * option with the same label, so re-sending a list keeps its ids; otherwise
     * it is created. Unlisted options are deactivated rather than deleted.
     *
     * @param  array<int, array{id?: int|string|null, label?: string|null, price: float|int|string}>  $options
     */
    public function syncOptions(array $options): void
    {
        $existing = $this->options()->get();
        $listedIds = collect($options)->pluck('id')->filter()->map(fn ($id) => (int) $id);

        $reusableByLabel = $existing
            ->whereNotIn('id', $listedIds)
            ->whereNotNull('label')
            ->sortBy('is_active')
            ->keyBy(fn (GameVariantOption $option) => mb_strtolower($option->label));

        $keptIds = [];

        foreach (array_values($options) as $position => $row) {
            $label = $row['label'] ?? null;

            $option = empty($row['id'])
                ? ($label === null ? null : $reusableByLabel->pull(mb_strtolower($label)))
                : $existing->firstWhere('id', (int) $row['id']);

            $option ??= new GameVariantOption(['game_variant_id' => $this->id]);
            $option->fill([
                'label' => $label,
                'price' => $row['price'],
                'sort_order' => $position,
                'is_active' => true,
            ])->save();

            $keptIds[] = $option->id;
        }

        $this->options()->whereNotIn('id', $keptIds)->update(['is_active' => false]);
        $this->unsetRelation('options')->unsetRelation('activeOptions');
    }

    /**
     * Keep the variant's price equal to its default option, so everything that
     * lists or sorts by the variant's price shows what the customer would pay.
     */
    public function syncPriceWithDefaultOption(): void
    {
        $defaultOption = $this->activeOptions()->first();

        if ($defaultOption !== null) {
            $this->update(['price' => $defaultOption->price]);
        }
    }

    /**
     * The option being bought: the one chosen, or else the default. Null when
     * the variant has no options.
     *
     * @throws RuntimeException when the chosen option cannot be bought on this variant
     */
    public function resolveOption(?int $optionId): ?GameVariantOption
    {
        $options = $this->activeOptions;

        if ($optionId === null) {
            return $options->first();
        }

        $option = $options->firstWhere('id', $optionId);

        if ($option === null) {
            $product = "بازی «{$this->game->title}» روی کنسول {$this->platform->name}";

            throw new RuntimeException($options->isEmpty()
                ? "{$product} گزینه‌ی قیمت ندارد."
                : "گزینه‌ی انتخاب‌شده برای {$product} معتبر نیست.");
        }

        return $option;
    }

    /**
     * What one copy costs with the given option: its price replaces the variant's.
     */
    public function priceWith(?GameVariantOption $option): float
    {
        return (float) ($option?->price ?? $this->price);
    }
}
