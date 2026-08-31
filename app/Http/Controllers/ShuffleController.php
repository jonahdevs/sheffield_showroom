<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\ShuffleCampaignData;
use App\Data\ShuffleRewardData;
use App\Exceptions\ShuffleUnavailableException;
use App\Models\ShuffleSession;
use App\Services\Rewards\ShuffleRewardService;
use App\Services\Rewards\ShuffleSessionService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's own shuffle. The only page in this application with no
 * sign-in behind it.
 *
 * Everything it knows comes from the token in the URL, and everything it says
 * is bounded by that: the reward, the campaign's name, and the kinds of reward
 * the animation cycles through. It never names the customer, never names the
 * purchase, and never says how many of anything are left - the last of those
 * because publishing live inventory lets somebody work out the odds, and this
 * is deliberately not that kind of product.
 *
 * Both routes are throttled. A token is 64 random characters, so guessing is
 * not a strategy, but a rate limit is what turns "not a strategy" into "not
 * worth attempting".
 */
class ShuffleController extends Controller
{
    public function __construct(
        private readonly ShuffleSessionService $sessions,
        private readonly ShuffleRewardService $rewards,
    ) {}

    /**
     * What the customer sees when they scan.
     *
     * Every refusal is a state to draw rather than an error page: a turn
     * already taken, a link that ran out, a promotion that ended. The one
     * exception is a token that names nothing, which is a 404 - there is
     * nothing to draw and nothing to explain.
     */
    public function show(string $token): Response
    {
        $session = ShuffleSession::query()
            ->with(['campaign.rewards', 'result.poolEntry.reward'])
            ->where('token', $token)
            ->first();

        if ($session === null) {
            abort(404);
        }

        /* Already shuffled: show them what they won rather than an apology.
           A customer refreshing the page an hour later wants their code. */
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

    /**
     * Running it.
     *
     * Nothing from the request body is read. The token in the URL is the whole
     * input, and what is won is decided entirely by
     * `ShuffleRewardService::claim()` - the browser is told the answer
     * afterwards and animates towards it.
     */
    public function store(string $token): RedirectResponse
    {
        try {
            $session = $this->sessions->forToken($token);

            $this->rewards->claim($session);
        } catch (ShuffleUnavailableException $exception) {
            /* Back to the same page, which will now draw the state this
               refusal describes - including "won" if they simply double
               tapped and the first tap succeeded. */
            return to_route('rewards.shuffle.show', $token);
        }

        return to_route('rewards.shuffle.show', $token);
    }

    /**
     * The page, in whichever state it is in.
     *
     * The reward names are sent so the animation has cards to cycle through.
     * Names and types only - never the counts, which would be the inventory.
     */
    private function page(ShuffleSession $session, string $state, ?ShuffleRewardData $reward = null): Response
    {
        return Inertia::render('rewards/Shuffle', [
            'state' => $state,
            /* What the promotion is, when it runs and what qualifies for it -
               and deliberately not how much of it is left. See
               `ShuffleCampaignData`. */
            'campaign' => ShuffleCampaignData::fromModel($session->campaign),
            'reward' => $reward,
            /* The faces the table deals, and what the panel beside it lists.
               Names, kinds, figures and terms: everything a customer is being
               offered, and no count of any of it. */
            'cards' => $session->campaign->rewards
                ->where('is_active', true)
                ->values()
                ->map(fn ($item) => [
                    'name' => $item->name,
                    'type' => $item->type->value,
                    'type_label' => $item->type->label(),
                    'value' => $item->readableValue(),
                    'terms' => $item->terms,
                ])
                ->all(),
        ]);
    }
}
