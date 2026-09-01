<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Enums\CampaignStatus;
use App\Exceptions\CampaignStateException;
use App\Models\RewardCampaign;
use Illuminate\Support\Facades\DB;

/**
 * Holds the two rules the database cannot.
 *
 * Publishing is one-way: after it the pool is written and the campaign is controlled
 * inventory. And only one campaign runs at a time - MySQL has no partial unique index
 * for "at most one row where status = active", so it is enforced here, inside a
 * transaction that locks what it checked. Two would leave eligibility guessing.
 */
class CampaignService
{
    public function __construct(private readonly RewardPoolService $pool) {}

    /**
     * @return int the number of reward units written
     */
    public function publish(RewardCampaign $campaign): int
    {
        return DB::transaction(function () use ($campaign): int {
            # Re-read under a lock, or two administrators pressing Publish at the same
            # moment both find a draft, both write a pool, and the campaign hands out
            # twice what it promised.
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
     * A draft cannot be started - it has no pool yet. Publish it instead.
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
     * The pool is untouched and turns already handed out keep their rewards.
     */
    public function pause(RewardCampaign $campaign): void
    {
        $this->moveTo($campaign, CampaignStatus::Paused);
    }

    /**
     * Nothing is deleted and nothing returns to the pool - results, redemptions and
     * the reporting behind them outlive the campaign.
     */
    public function complete(RewardCampaign $campaign): void
    {
        $this->moveTo($campaign, CampaignStatus::Completed);
    }

    public function cancel(RewardCampaign $campaign): void
    {
        $this->moveTo($campaign, CampaignStatus::Cancelled);
    }

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
     * Only ever called from inside a transaction that has already locked the campaign
     * being changed, so the row it finds cannot start or stop underneath the check.
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
