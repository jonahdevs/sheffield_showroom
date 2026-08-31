<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\OptionData;
use App\Data\PurchaseRowData;
use App\Enums\PurchaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PurchaseRequest;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\ShuffleSession;
use App\Services\Rewards\RewardEligibilityService;
use App\Support\Http\PageSize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What somebody bought, and for how much.
 *
 * The screen carries the reward question with it: every row says whether that
 * sale has earned a turn, has already been given one, or why it has not. That
 * is the only reason this table exists, so hiding the answer one click away
 * would make the whole feature invisible to the person at the counter.
 */
class PurchaseController extends Controller
{
    public function __construct(private readonly RewardEligibilityService $eligibility) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Purchase::class);

        $viewer = $request->user();
        $filters = $this->filters($request);

        $purchases = $this->filtered($filters)
            ->with(['customer', 'shuffleSession.result'])
            ->latest('purchased_at')
            ->latest('id')
            ->paginate(PageSize::from($request))
            ->withQueryString()
            ->through(fn (Purchase $purchase) => PurchaseRowData::fromModel(
                $purchase,
                $viewer,
                $this->eligibility,
            ));

        return Inertia::render('admin/purchases/Index', [
            'purchases' => $purchases,
            'filters' => $filters,
            'statuses' => PurchaseStatus::options(),
            'page_sizes' => PageSize::OPTIONS,
            'can' => [
                'create' => $viewer->can('create', Purchase::class),
                'update' => $viewer->can('update', new Purchase),
                'shuffle' => $viewer->can('create', ShuffleSession::class),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Purchase::class);

        return Inertia::render('admin/purchases/Form', [
            'purchase' => null,
            'statuses' => PurchaseStatus::options(),
            'customers' => $this->customerOptions(),
        ]);
    }

    public function edit(Purchase $purchase): Response
    {
        $this->authorize('update', $purchase);

        return Inertia::render('admin/purchases/Form', [
            'purchase' => [
                'id' => $purchase->id,
                'customer_id' => $purchase->customer_id,
                'visit_id' => $purchase->visit_id,
                'reference' => $purchase->reference,
                'amount' => $purchase->amount,
                'status' => $purchase->status->value,
                'purchased_at' => $purchase->purchased_at->toDateTimeLocalString(),
            ],
            'statuses' => PurchaseStatus::options(),
            'customers' => $this->customerOptions(),
        ]);
    }

    public function store(PurchaseRequest $request): RedirectResponse
    {
        $purchase = new Purchase($request->validated());
        $purchase->created_by = $request->user()->id;
        $purchase->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The purchase has been recorded.'),
        ]);

        return to_route('admin.purchases.index');
    }

    public function update(PurchaseRequest $request, Purchase $purchase): RedirectResponse
    {
        $purchase->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The purchase has been saved.'),
        ]);

        return to_route('admin.purchases.index');
    }

    /**
     * Soft deleted, and refused outright once a turn has been given against
     * it - see `PurchasePolicy::delete`, which says so before the foreign key
     * has to.
     */
    public function destroy(Purchase $purchase): RedirectResponse
    {
        $this->authorize('delete', $purchase);

        $purchase->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The purchase has been removed.'),
        ]);

        return back();
    }

    /**
     * @param  array{search: string, status: string}  $filters
     * @return Builder<Purchase>
     */
    private function filtered(array $filters): Builder
    {
        return Purchase::query()
            ->when($filters['status'] !== '', fn (Builder $query) => $query
                ->where('status', $filters['status']))
            ->when($filters['search'] !== '', fn (Builder $query) => $query
                ->where(fn (Builder $inner) => $inner
                    ->where('reference', 'like', "%{$filters['search']}%")
                    ->orWhereHas('customer', fn (Builder $customer) => $customer
                        ->search($filters['search']))));
    }

    /**
     * @return array{search: string, status: string}
     */
    private function filters(Request $request): array
    {
        $status = $request->string('status')->toString();

        return [
            'search' => $request->string('search')->trim()->toString(),
            'status' => in_array($status, PurchaseStatus::values(), true) ? $status : '',
        ];
    }

    /**
     * Who the sale can be filed against.
     *
     * The same combobox shape every other picker in this application uses, so
     * a salesperson recognises it - see `OptionData`.
     *
     * @return array<int, OptionData>
     */
    private function customerOptions(): array
    {
        return Customer::query()
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->map(fn (Customer $customer) => new OptionData(
                value: $customer->id,
                label: $customer->displayName(),
                hint: $customer->phone,
            ))
            ->all();
    }
}
