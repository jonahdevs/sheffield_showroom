<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RewardType;
use App\Enums\RewardValueUnit;
use App\Policies\RewardPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\RewardFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
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
 * @property-read int|null $attachments_count only where the query asked for it
 */
#[UsePolicy(RewardPolicy::class)]
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
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

    public function readableValue(): ?string
    {
        if ($this->value === null || $this->value_unit === null) {
            return null;
        }

        return $this->value_unit->format((float) $this->value);
    }

    public function readableName(): string
    {
        if ($this->name !== '') {
            return $this->name;
        }

        return $this->product?->name ?? 'Reward';
    }

    /**
     * @param  Builder<Reward>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }

        $query->where('name', 'like', '%'.$term.'%');
    }
}
