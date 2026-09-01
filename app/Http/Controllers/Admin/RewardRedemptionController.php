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

# Found by the quoted code, never the QR link, which expired the morning after the shuffle.
class RewardRedemptionController extends Controller
{
    public function __construct(private readonly RewardRedemptionService $redemptions) {}

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

        return to_route('admin.rewards.redeem.index', ['code' => $result->code]);
    }
}
