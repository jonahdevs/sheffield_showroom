<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\CustomerOptionData;
use App\Data\OptionData;
use App\Data\VisitFormData;
use App\Data\VisitRowData;
use App\Enums\CustomerSource;
use App\Enums\CustomerType;
use App\Enums\Permission;
use App\Enums\VisitPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VisitRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Models\Visit;
use App\Support\Http\PageSize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calls at the showroom: who came, why, and what they were shown.
 *
 * Every query here runs through `visible()`, which is what keeps a
 * salesperson's list to their own visits and a manager's to the whole floor.
 */
class VisitController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Visit::class);

        $viewer = $request->user();
        $filters = $this->filters($request);

        $visits = $this->visible($viewer)
            ->with(['customer', 'creator'])
            /* Counted rather than loaded: the row shows how many products were
               shown, not which, and fifty visits should not be fifty reads. */
            ->withCount('products')
            ->when(
                $filters['search'] !== '',
                fn (Builder $query) => $query->search($filters['search']),
            )
            ->when(
                $filters['purpose'] !== '',
                fn (Builder $query) => $query->forPurpose(VisitPurpose::from($filters['purpose'])),
            )
            /* Newest visit first, and the id to break a tie - several visits
               land on the same round hour, and without it the pager repeats
               rows across pages. */
            ->orderByDesc('visited_at')
            ->orderByDesc('id')
            ->paginate(PageSize::from($request))
            ->withQueryString()
            ->through(VisitRowData::fromModel(...));

        return Inertia::render('admin/visits/Index', [
            'visits' => $visits,
            'filters' => $filters,
            'purposes' => VisitPurpose::options(),
            'page_sizes' => PageSize::OPTIONS,
            'total' => $this->visible($viewer)->count(),
            /* A salesperson sees their own visits; saying so stops the list
               reading as though the showroom had a quiet week. */
            'scoped_to_own' => ! $viewer->can(Permission::VisitsViewAny->value),
            'can' => [
                'create' => $viewer->can('create', Visit::class),
                'update' => $viewer->can(Permission::VisitsUpdate->value),
                'delete' => $viewer->can(Permission::VisitsDelete->value),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Visit::class);

        return Inertia::render('admin/visits/Form', [
            'visit' => null,
            ...$this->formOptions(),
        ]);
    }

    public function edit(Visit $visit): Response
    {
        $this->authorize('update', $visit);

        $visit->load('products', 'customer');

        return Inertia::render('admin/visits/Form', [
            'visit' => VisitFormData::fromModel($visit),
            ...$this->formOptions(),
        ]);
    }

    public function store(VisitRequest $request): RedirectResponse
    {
        $visit = DB::transaction(function () use ($request) {
            /* The customer first: they may be new, and the visit cannot be
               filed until there is somebody to file it against. */
            $customer = $request->resolveCustomer();

            $visit = new Visit($request->visitAttributes());
            $visit->customer_id = $customer->id;
            $visit->visited_at = $request->visitedAt();
            $visit->created_by = $request->user()->id;
            $visit->save();

            $visit->products()->sync($request->productIds());

            return $visit;
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The visit by :name has been added.', [
                'name' => $visit->customer->displayName(),
            ]),
        ]);

        return to_route('admin.visits.index');
    }

    public function update(VisitRequest $request, Visit $visit): RedirectResponse
    {
        DB::transaction(function () use ($request, $visit) {
            $visit->fill($request->visitAttributes());
            $visit->customer_id = $request->resolveCustomer()->id;
            $visit->visited_at = $request->visitedAt();
            $visit->save();

            /* `sync` rather than `attach`: what was shown is the list the form
               came back with, not that list added to the old one. */
            $visit->products()->sync($request->productIds());
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The visit by :name has been saved.', [
                'name' => $visit->customer->displayName(),
            ]),
        ]);

        return to_route('admin.visits.index');
    }

    /**
     * Soft deleted. A visit is what the floor is measured by, and a month that
     * quietly loses a row is a month nobody can reconcile.
     */
    public function destroy(Visit $visit): RedirectResponse
    {
        $this->authorize('delete', $visit);

        $name = $visit->customer->displayName();

        $visit->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The visit by :name has been removed.', ['name' => $name]),
        ]);

        return back();
    }

    /**
     * The visits this user is allowed to see at all.
     *
     * `visits.view.any` is the whole floor; anything less is what they logged
     * themselves. The policy says the same thing for one record - this is the
     * query that says it for a list.
     *
     * @return Builder<Visit>
     */
    private function visible(User $viewer): Builder
    {
        return Visit::query()->unless(
            $viewer->can(Permission::VisitsViewAny->value),
            fn (Builder $query) => $query->loggedBy($viewer),
        );
    }

    /**
     * What both comboboxes choose between, plus the two fixed lists.
     *
     * The lists are sent whole rather than searched over the wire: the box
     * filters as you type, and a round trip per keystroke is a worse trade at
     * this size than a few hundred rows in the payload.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'customers' => Customer::query()
                ->orderBy('name')
                ->orderBy('company_name')
                ->get()
                ->map(CustomerOptionData::fromModel(...))
                ->values(),
            'products' => Product::query()
                ->orderBy('name')
                /* `image_path` among them, or `imageUrl()` has nothing to
                   build the thumbnail from and every tile comes back blank. */
                ->get(['id', 'name', 'sku', 'image_path'])
                ->map(OptionData::fromProduct(...))
                ->values(),
            'types' => CustomerType::options(),
            'purposes' => VisitPurpose::options(),
            'sources' => CustomerSource::options(),
        ];
    }

    /**
     * @return array{search: string, purpose: string}
     */
    private function filters(Request $request): array
    {
        $purpose = $request->string('purpose')->toString();

        return [
            'search' => $request->string('search')->trim()->toString(),
            'purpose' => in_array($purpose, VisitPurpose::values(), true) ? $purpose : '',
        ];
    }
}
