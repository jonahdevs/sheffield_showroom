<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RewardType;
use App\Enums\RewardValueUnit;
use Carbon\CarbonImmutable;
use Database\Factories\RewardFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing the showroom is willing to give away, described once.
 *
 * Written on its own and reused: the same free kitchen audit goes into as many
 * campaigns as want it, and every one of them describes it identically because
 * none of them describes it at all. How many exist and how long a winner has
 * belong to `CampaignReward`, which is the attachment rather than the offer.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property RewardType $type
 * @property int|null $product_id
 * @property string|null $value
 * @property RewardValueUnit|null $value_unit
 * @property string|null $terms
 * @property int|null $default_validity_days
 * @property bool $is_active
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Reward extends Model
{
    /** @use HasFactory<RewardFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'type',
        'product_id',
        'value',
        'value_unit',
        'terms',
        'default_validity_days',
        'is_active',
        'created_by',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => RewardType::class,
            'value' => 'decimal:2',
            'value_unit' => RewardValueUnit::class,
            'default_validity_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The item won, for a reward that is a thing rather than a discount or a
     * service. Null for every other type.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Every campaign that has taken this reward.
     *
     * @return HasMany<CampaignReward, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(CampaignReward::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The number on the card as somebody reads it: "10%" rather than "10".
     *
     * Null when the reward carries no figure, which is most of them - a free
     * kitchen audit is worth what its terms say it is, and a product is worth
     * whatever it is.
     */
    public function readableValue(): ?string
    {
        if ($this->value === null || $this->value_unit === null) {
            return null;
        }

        return $this->value_unit->format((float) $this->value);
    }

    /**
     * What to call this reward on a card.
     *
     * A product reward may be left unnamed on the form, because the product
     * already has a name and typing it twice is how the two drift apart.
     */
    public function readableName(): string
    {
        if ($this->name !== '') {
            return $this->name;
        }

        return $this->product?->name ?? 'Reward';
    }

    /**
     * The rewards still on offer for new campaigns.
     *
     * @param  Builder<Reward>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
