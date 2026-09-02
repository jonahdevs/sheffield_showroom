<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\ShuffleUnavailableException;
use App\Http\Controllers\Controller;
use App\Services\Rewards\RewardRedemptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The counter has no screen of its own: it is a dialog on the Redeemed list, which
 * looks the code up through `RewardWinnerController`. Only the handover posts here.
 */
# Found by the quoted code, never the QR link, which expired the morning after the shuffle.
class RewardRedemptionController extends Controller
{
    public function __construct(private readonly RewardRedemptionService $redemptions) {}

    # Re-resolved from the code, never trusted from a hidden field: somebody else may
    # have redeemed it since the screen was drawn.
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

        # `back()`, so the dialog reopens on the code it was standing on and the list
        # behind it is redrawn with the handover in it.
        return back();
    }
}
