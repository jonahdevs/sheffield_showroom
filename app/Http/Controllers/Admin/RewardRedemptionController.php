<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\ShuffleRewardData;
use App\Exceptions\ShuffleUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\ShuffleResult;
use App\Services\Rewards\RewardRedemptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Handing a won reward over, weeks later, at whatever desk the customer walks
 * up to.
 *
 * Found by the code they quote rather than by anything they still hold on a
 * phone - the QR link expired the morning after the shuffle, and is no use in
 * October. The code is the whole interface: type it, read back what it is and
 * who won it, hand the thing over, write it down.
 */
class RewardRedemptionController extends Controller
{
    public function __construct(private readonly RewardRedemptionService $redemptions) {}

    /**
     * The lookup screen, and what a code resolved to.
     *
     * A code that finds nothing comes back as `found: false` rather than as an
     * error: staff mistype these, and a blank answer with "we cannot find
     * that" is the right response to a typo.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ShuffleResult::class);

        $code = $request->string('code')->trim()->toString();
        $result = $code === '' ? null : $this->redemptions->find($code);

        return Inertia::render('admin/rewards/Redeem', [
            'code' => $code,
            'searched' => $code !== '',
            'reward' => $result === null
                ? null
                : ShuffleRewardData::fromModel($result, withCustomer: true),
            'can' => [
                'redeem' => $result !== null && $request->user()->can('redeem', $result),
            ],
        ]);
    }

    /**
     * Records the reward as handed over.
     *
     * The result is resolved from the code again rather than trusted from a
     * hidden field: what the screen showed a minute ago is not what the
     * database has to agree to now, and somebody else may have redeemed it in
     * between.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $this->redemptions->find($validated['code']);

        if ($result === null) {
            return back()->withErrors(['code' => 'No reward has that code.']);
        }

        $this->authorize('redeem', $result);

        try {
            $this->redemptions->redeem(
                $result,
                $request->user(),
                $validated['notes'] ?? null,
            );
        } catch (ShuffleUnavailableException $exception) {
            return back()->withErrors(['code' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Reward :code has been redeemed.', ['code' => $result->code]),
        ]);

        return to_route('admin.rewards.redeem.index', ['code' => $result->code]);
    }
}
