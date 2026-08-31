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
 * Handing the reward over, and writing that down.
 *
 * Weeks after the shuffle, at a different desk, by whoever is on that day. The
 * reward is found by the code the customer quotes rather than by anything they
 * still hold on their phone - a link that expired the next morning is no use
 * in October.
 */
class RewardRedemptionService
{
    /**
     * The reward behind a code, whatever state it is in.
     *
     * Returns null rather than throwing on an unknown code: staff mistype
     * these, and a blank result with "we cannot find that" is the right answer
     * to a typo. The states that are found but unusable are the ones worth an
     * exception, because they need explaining.
     */
    public function find(string $code): ?ShuffleResult
    {
        return ShuffleResult::query()
            ->with(['poolEntry.reward', 'session.customer', 'redemption.redeemer'])
            ->where('code', $this->normalise($code))
            ->first();
    }

    /**
     * Records the reward as handed over.
     *
     * The status and the redemption row are written together, inside a
     * transaction that locks the result first. Two members of staff redeeming
     * the same code at the same moment would otherwise both read `unredeemed`
     * and both write - and while the unique index on `shuffle_result_id` would
     * refuse the second, an error is a worse answer than "already redeemed".
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

            /* Reads the date rather than the status, so a reward whose window
               closed before anything swept it is still refused. */
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
     * Codes are stored upper case; somebody typing one in reads it off a phone
     * screen and will not be careful about that, or about the space they
     * pasted with it.
     */
    private function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }
}
