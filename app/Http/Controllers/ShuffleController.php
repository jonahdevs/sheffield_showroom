<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\ShuffleCampaignData;
use App\Data\ShuffleRewardData;
use App\Exceptions\ShuffleUnavailableException;
use App\Models\CampaignReward;
use App\Models\ShuffleSession;
use App\Services\Rewards\ShuffleRewardService;
use App\Services\Rewards\ShuffleSessionService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

# The one unauthenticated page: the token in the URL is the whole input, and
# nothing it sends is read. Never publishes remaining counts - live inventory
# lets somebody work out the odds.
class ShuffleController extends Controller
{
    public function __construct(
        private readonly ShuffleSessionService $sessions,
        private readonly ShuffleRewardService $rewards,
    ) {}

    public function show(string $token): Response
    {
        $session = ShuffleSession::query()
            ->with([
                'campaign.rewards.reward',
                'result.poolEntry.reward.reward.product:id,name',
            ])
            ->where('token', $token)
            ->first();

        if ($session === null) {
            abort(404);
        }

        if ($session->result !== null) {
            return $this->page($session, 'won', ShuffleRewardData::fromModel($session->result));
        }

        try {
            $this->sessions->assertShuffleable($session);
        } catch (ShuffleUnavailableException $exception) {
            return $this->page($session, $exception->reason);
        }

        if ($session->campaign->availableCount() === 0) {
            return $this->page($session, 'pool_empty');
        }

        return $this->page($session, 'ready');
    }

    public function store(string $token): RedirectResponse
    {
        try {
            $session = $this->sessions->forToken($token);

            $this->rewards->claim($session);
        } catch (ShuffleUnavailableException $exception) {
            return to_route('rewards.shuffle.show', $token);
        }

        return to_route('rewards.shuffle.show', $token);
    }

    private function page(ShuffleSession $session, string $state, ?ShuffleRewardData $reward = null): Response
    {
        return Inertia::render('rewards/Shuffle', [
            'state' => $state,
            'campaign' => ShuffleCampaignData::fromModel($session->campaign),
            'reward' => $reward,
            # Names, kinds, figures and terms only - never a count.
            'cards' => $session->campaign->rewards
                ->where('is_active', true)
                ->values()
                ->map(fn (CampaignReward $item) => [
                    'name' => $item->reward->readableName(),
                    'type' => $item->reward->type->value,
                    'type_label' => $item->reward->type->label(),
                    'value' => $item->reward->readableValue(),
                    'terms' => $item->reward->terms,
                ])
                ->all(),
        ]);
    }
}
