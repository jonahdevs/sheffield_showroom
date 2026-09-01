<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RewardRedemptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $shuffle_result_id
 * @property int|null $redeemed_by
 * @property CarbonImmutable $redeemed_at
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class RewardRedemption extends Model
{
    /** @use HasFactory<RewardRedemptionFactory> */
    use HasFactory;

    protected $fillable = [
        'shuffle_result_id',
        'redeemed_by',
        'redeemed_at',
        'notes',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'redeemed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ShuffleResult, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(ShuffleResult::class, 'shuffle_result_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function redeemer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by');
    }
}
