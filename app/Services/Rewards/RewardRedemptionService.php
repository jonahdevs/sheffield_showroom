<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Enums\RewardResultStatus;
use App\Exceptions\ShuffleUnavailableException;
use App\Models\RewardRedemption;
use App\Models\ShuffleResult;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Found by the code the customer quotes, never by anything still on their phone - the
 * shuffle link expired the next morning and this happens weeks later.
 */
class RewardRedemptionService
{
    /**
     * Null rather than an exception on an unknown code - staff mistype these. Only a
     * code that is found but unusable is worth explaining.
     */
    public function find(string $code): ?ShuffleResult
    {
        return ShuffleResult::query()
            ->with(['poolEntry.reward.reward.product:id,name', 'session.customer', 'redemption.redeemer'])
            ->where('code', $this->normalise($code))
            ->first();
    }

    /**
     * Status and redemption row are written together inside a transaction that locks
     * the result first. Two staff redeeming one code at the same moment would otherwise
     * both read `unredeemed` and both write; the unique index would refuse the second,
     * but an integrity error is a worse answer than "already redeemed".
     */
    public function redeem(
        ShuffleResult $result,
        ?User $staff = null,
        ?string $notes = null,
        ?CarbonImmutable $at = null,
    ): RewardRedemption {
        $at ??= CarbonImmutable::now();

        return DB::transaction(function () use ($result, $staff, $notes, $at): RewardRedemption {
            $result = ShuffleResult::query()->lockForUpdate()->findOrFail($result->id);

            if ($result->status === RewardResultStatus::Redeemed) {
                throw ShuffleUnavailableException::alreadyUsed();
            }

            # Reads the date, not the status, so a reward whose window closed before
            # `rewards:expire` swept it is still refused.
            if (! $result->isRedeemable($at)) {
                throw ShuffleUnavailableException::expired();
            }

            $redemption = $result->redemption()->create([
                'redeemed_by' => $staff?->id,
                'redeemed_at' => $at,
                'notes' => $notes,
            ]);

            $result->forceFill(['status' => RewardResultStatus::Redeemed])->save();

            return $redemption;
        });
    }

    /**
     * Codes are stored upper case, and are typed in off a phone screen.
     */
    private function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }
}
