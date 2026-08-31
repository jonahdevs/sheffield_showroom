<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Enums\CampaignStatus;
use App\Exceptions\CampaignStateException;
use App\Models\RewardCampaign;
use Illuminate\Support\Facades\DB;

/**
 * Moving a campaign through its life, and holding the two rules that the
 * database cannot.
 *
 * The first: publishing is one-way. A draft is an administrator's to reshape;
 * everything after it is controlled inventory, because the pool has been
 * written and people have been told what they are playing for.
 *
 * The second: only one campaign runs at a time. MySQL has no partial unique
 * index to say "at most one row where status = active", so it is said here,
 * inside a transaction that locks what it checked. Two would leave eligibility
 * guessing which promotion a purchase was measured against.
 */
class CampaignService
{
    public function __construct(private readonly RewardPoolService $pool) {}

    /**
     * Writes the pool and moves the campaign out of draft.
     *
     * Whether it lands on `active` or `scheduled` is the calendar's business,
     * not the administrator's: a campaign that starts next Monday is scheduled
     * until Monday, and one with no start date is running the moment it is
     * published.
     *
     * @return int the number of reward units written
     */
    public function publish(RewardCampaign $campaign): int
    {
        return DB::transaction(function () use ($campaign): int {
            /* Re-read under a lock. Two administrators pressing Publish at the
               same moment would otherwise both find a draft and both write a
               pool, and the campaign would hand out twice what it promised. */
            $campaign = RewardCampaign::query()
                ->lockForUpdate()
                ->findOrFail($campaign->id);

            if ($campaign->status->isPublished()) {
                throw CampaignStateException::alreadyPublished();
            }

            $loadable = $campaign->rewards()
                ->where('is_active', true)
                ->where('quantity', '>', 0)
                ->exists();

            if (! $loadable) {
                throw CampaignStateException::nothingToPublish();
            }

            $status = $this->statusOnPublication($campaign);

            if ($status === CampaignStatus::Active) {
                $this->refuseIfAnotherIsRunning($campaign);
            }

            $written = $this->pool->generate($campaign);

            $campaign->forceFill(['status' => $status])->save();

            return $written;
        });
    }

    /**
     * Starts a campaign that was scheduled or paused.
     *
     * A draft cannot be started - it has no pool yet, and starting one would
     * be a promotion with nothing behind it. Publish it instead.
     */
    public function activate(RewardCampaign $campaign): void
    {
        DB::transaction(function () use ($campaign): void {
            $campaign = RewardCampaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if ($campaign->status === CampaignStatus::Draft) {
                throw CampaignStateException::notPublished();
            }

            if ($campaign->status->isClosed()) {
                throw CampaignStateException::closed();
            }

            $this->refuseIfAnotherIsRunning($campaign);

            $campaign->forceFill(['status' => CampaignStatus::Active])->save();
        });
    }

    /**
     * Stops a campaign without ending it. The pool is untouched and the turns
     * already handed out keep their rewards - pausing is for a showroom that
     * wants to think, not for undoing what has happened.
     */
    public function pause(RewardCampaign $campaign): void
    {
        $this->moveTo($campaign, CampaignStatus::Paused);
    }

    /**
     * Ends a campaign for good.
     *
     * Nothing is deleted and nothing is returned to the pool. The results, the
     * redemptions and the reporting behind them all outlive the campaign -
     * that is the whole point of keeping them.
     */
    public function complete(RewardCampaign $campaign): void
    {
        $this->moveTo($campaign, CampaignStatus::Completed);
    }

    public function cancel(RewardCampaign $campaign): void
    {
        $this->moveTo($campaign, CampaignStatus::Cancelled);
    }

    /**
     * Whether a published campaign is running now or waiting for its start
     * date.
     */
    private function statusOnPublication(RewardCampaign $campaign): CampaignStatus
    {
        return $campaign->starts_at !== null && $campaign->starts_at->isFuture()
            ? CampaignStatus::Scheduled
            : CampaignStatus::Active;
    }

    private function moveTo(RewardCampaign $campaign, CampaignStatus $status): void
    {
        DB::transaction(function () use ($campaign, $status): void {
            $campaign = RewardCampaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if ($campaign->status->isClosed()) {
                throw CampaignStateException::closed();
            }

            $campaign->forceFill(['status' => $status])->save();
        });
    }

    /**
     * The one-active-campaign rule.
     *
     * Called only from inside a transaction that has already locked the
     * campaign being changed, so the row it finds cannot start or stop
     * underneath the check.
     */
    private function refuseIfAnotherIsRunning(RewardCampaign $campaign): void
    {
        $running = RewardCampaign::query()
            ->where('status', CampaignStatus::Active)
            ->whereKeyNot($campaign->id)
            ->lockForUpdate()
            ->first();

        if ($running !== null) {
            throw CampaignStateException::anotherIsActive($running->name);
        }
    }
}
