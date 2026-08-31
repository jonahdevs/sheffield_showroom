<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\ShuffleSessionData;
use App\Exceptions\ShuffleUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\ShuffleSession;
use App\Services\Rewards\RewardEligibilityService;
use App\Services\Rewards\ShuffleRewardService;
use App\Services\Rewards\ShuffleSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Giving a customer their turn, and running it for them when they cannot.
 *
 * The staff fallback calls exactly the same `ShuffleRewardService::claim()`
 * the customer's phone does. There is deliberately no second implementation:
 * two ways of choosing a reward would be two ways of choosing it wrongly, and
 * the one behind the counter would be the one nobody tested.
 */
class ShuffleSessionController extends Controller
{
    public function __construct(
        private readonly ShuffleSessionService $sessions,
        private readonly ShuffleRewardService $rewards,
        private readonly RewardEligibilityService $eligibility,
    ) {}

    /**
     * Mints the turn a qualifying purchase has earned.
     *
     * The refusal is shown rather than swallowed. A member of staff standing
     * in front of a customer needs to know that the sale was two thousand
     * short, not merely that there is no button.
     */
    public function store(Request $request, Purchase $purchase): RedirectResponse
    {
        $this->authorize('create', ShuffleSession::class);

        $refusal = $this->eligibility->refusalFor($purchase);

        if ($refusal !== null) {
            return back()->withErrors(['shuffle' => $refusal]);
        }

        $session = $this->sessions->mintFor($purchase, $request->user());

        if ($session === null) {
            return back()->withErrors([
                'shuffle' => 'This purchase cannot be given a shuffle.',
            ]);
        }

        return to_route('admin.shuffles.show', $session);
    }

    /**
     * The QR screen: a code big enough to scan from across a counter.
     *
     * This is the only authenticated page that carries a session token, and it
     * carries it because that is the whole job - see `ShuffleSessionData`.
     */
    public function show(Request $request, ShuffleSession $session): Response
    {
        $this->authorize('view', $session);

        $session->load(['customer', 'campaign', 'result.poolEntry.reward.reward.product:id,name']);

        $viewer = $request->user();

        return Inertia::render('admin/rewards/Shuffle', [
            'session' => ShuffleSessionData::fromModel($session),
            'campaign_name' => $session->campaign->name,
            'can' => [
                'run' => $viewer->can('run', $session),
                'cancel' => $viewer->can('cancel', $session),
            ],
        ]);
    }

    /**
     * The fallback: staff runs the shuffle on the showroom screen because the
     * customer's phone will not scan, or they do not have one with them.
     */
    public function run(ShuffleSession $session): RedirectResponse
    {
        $this->authorize('run', $session);

        try {
            $this->rewards->claim($session);
        } catch (ShuffleUnavailableException $exception) {
            return back()->withErrors(['shuffle' => $exception->getMessage()]);
        }

        return back();
    }

    /** Taking a turn back before it is used. */
    public function destroy(ShuffleSession $session): RedirectResponse
    {
        $this->authorize('cancel', $session);

        try {
            $this->sessions->cancel($session);
        } catch (ShuffleUnavailableException $exception) {
            return back()->withErrors(['shuffle' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The shuffle has been cancelled.'),
        ]);

        return back();
    }
}
