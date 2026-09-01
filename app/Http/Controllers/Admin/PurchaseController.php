<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\OptionData;
use App\Data\PurchaseRowData;
use App\Enums\PurchaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PurchaseRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\ShuffleSession;
use App\Services\Rewards\RewardEligibilityService;
use App\Support\Http\PageSize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
    public function __construct(private readonly RewardEligibilityService $eligibility) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Purchase::class);

        $viewer = $request->user();
        $filters = $this->filters($request);

        $purchases = $this->filtered($filters)
            ->with(['customer', 'shuffleSession.result', 'products:id,name'])
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
            'products' => $this->productOptions(),
            'selected_products' => [],
        ]);
    }

    public function edit(Purchase $purchase): Response
    {
        $this->authorize('update', $purchase);

        $purchase->load('products:id,name');

        return Inertia::render('admin/purchases/Form', [
            'purchase' => [
                'id' => $purchase->id,
                'customer_id' => $purchase->customer_id,
                'visit_id' => $purchase->visit_id,
                'product_ids' => $purchase->products->pluck('id')->all(),
                'reference' => $purchase->reference,
                'amount' => $purchase->amount,
                'status' => $purchase->status->value,
                'purchased_at' => $purchase->purchased_at->toDateTimeLocalString(),
            ],
            'statuses' => PurchaseStatus::options(),
            'customers' => $this->customerOptions(),
            'products' => $this->productOptions(),
            # `products` above is only the floor as it stands today. Without these a chip
            # for a product withdrawn since the sale has no name, and the next save posts
            # back a selection with a hole in it.
            'selected_products' => $purchase->products
                ->map(fn (Product $product) => OptionData::fromProduct($product))
                ->all(),
        ]);
    }

    public function store(PurchaseRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $purchase = new Purchase($this->columns($request));
            $purchase->created_by = $request->user()->id;
            $purchase->save();

            $this->syncProducts($purchase, $request);
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The purchase has been recorded.'),
        ]);

        return to_route('admin.purchases.index');
    }

    public function update(PurchaseRequest $request, Purchase $purchase): RedirectResponse
    {
        DB::transaction(function () use ($request, $purchase): void {
            $purchase->update($this->columns($request));

            $this->syncProducts($purchase, $request);
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The purchase has been saved.'),
        ]);

        return to_route('admin.purchases.index');
    }

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
     * `product_ids` is a pivot, written by `syncProducts()`. Handing it to `fill()` has
     * Eloquent discard it silently, which reads as a working feature until somebody
     * checks the table.
     *
     * @return array<string, mixed>
     */
    private function columns(PurchaseRequest $request): array
    {
        return $request->safe()->except('product_ids');
    }

    /**
     * An absent `product_ids` and an empty one are different answers. A payload that never
     * mentions it (an import, a till, a test correcting an amount) says nothing about
     * products; clearing them would strip a paired reward's reason off an untouched sale.
     * An empty array is the picker deliberately emptied, and must clear the rows.
     */
    private function syncProducts(Purchase $purchase, PurchaseRequest $request): void
    {
        if (! $request->has('product_ids')) {
            return;
        }

        $purchase->products()->sync(
            array_map(intval(...), $request->validated('product_ids') ?? []),
        );
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

    /**
     * Only what is still on offer; a withdrawn product keeps its place via `selected_products`.
     *
     * @return array<int, OptionData>
     */
    private function productOptions(): array
    {
        return Product::query()
            ->orderBy('name')
            ->get()
            ->map(OptionData::fromProduct(...))
            ->all();
    }
}
