<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductSource;
use App\Enums\ProductStatus;
use App\Policies\ProductPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $name
 * @property string|null $sku
 * @property string|null $model_number
 * @property string|null $image_path
 * @property ProductStatus $status
 * @property ProductSource $source
 * @property int|null $external_id
 * @property CarbonImmutable|null $synced_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[UsePolicy(ProductPolicy::class)]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    public const IMAGE_DIRECTORY = 'products';

    protected $fillable = [
        'name',
        'sku',
        'model_number',
        'image_path',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'source' => ProductSource::class,
            'status' => ProductStatus::class,
            'synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    # A synced row holds the website's absolute URL, not a copy of the file.
    public function imageUrl(): ?string
    {
        if ($this->image_path === null) {
            return null;
        }

        return str_starts_with($this->image_path, 'http')
            ? $this->image_path
            : Storage::disk('public')->url($this->image_path);
    }

    public function isSynced(): bool
    {
        return $this->source === ProductSource::Website;
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function ofStatus(Builder $query, ProductStatus $status): void
    {
        $query->where('status', $status);
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

        $like = '%'.$term.'%';

        $query->where(fn (Builder $inner) => $inner
            ->where('name', 'like', $like)
            ->orWhere('sku', 'like', $like));
    }
}
