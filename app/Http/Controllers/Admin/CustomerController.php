<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\CustomerFormData;
use App\Data\CustomerRowData;
use App\Enums\CustomerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomerRequest;
use App\Models\Customer;
use App\Support\Http\PageSize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The people and organisations who visit the showroom.
 */
class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Customer::class);

        $viewer = $request->user();
        $filters = $this->filters($request);

        $customers = Customer::query()
            ->when($filters['search'] !== '', fn (Builder $query) => $query->search($filters['search']))
            ->when(
                $filters['type'] !== '',
                fn (Builder $query) => $query->ofType(CustomerType::from($filters['type'])),
            )
            ->latest('id')
            ->paginate(PageSize::from($request))
            ->withQueryString()
            ->through(CustomerRowData::fromModel(...));

        return Inertia::render('admin/customers/Index', [
            'customers' => $customers,
            'filters' => $filters,
            'types' => CustomerType::options(),
            'page_sizes' => PageSize::OPTIONS,
            'counts' => [
                'all' => Customer::query()->count(),
                'individual' => Customer::query()->ofType(CustomerType::Individual)->count(),
                'company' => Customer::query()->ofType(CustomerType::Company)->count(),
            ],
            'can' => [
                'create' => $viewer->can('create', Customer::class),
                'update' => $viewer->can('update', new Customer),
                'delete' => $viewer->can('delete', new Customer),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Customer::class);

        return Inertia::render('admin/customers/Form', [
            'customer' => null,
            'types' => CustomerType::options(),
            'default_country' => 'Kenya',
        ]);
    }

    public function edit(Customer $customer): Response
    {
        $this->authorize('update', $customer);

        return Inertia::render('admin/customers/Form', [
            'customer' => CustomerFormData::fromModel($customer),
            'types' => CustomerType::options(),
            'default_country' => 'Kenya',
        ]);
    }

    public function store(CustomerRequest $request): RedirectResponse
    {
        $customer = new Customer($request->validated());
        $customer->created_by = $request->user()->id;
        $customer->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been added.', ['name' => $customer->displayName()]),
        ]);

        return to_route('admin.customers.index');
    }

    public function update(CustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been saved.', ['name' => $customer->displayName()]),
        ]);

        return to_route('admin.customers.index');
    }

    /**
     * Soft deleted. A customer is attached to the visits they made, and a hard
     * delete would take that history with them.
     */
    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);

        $name = $customer->displayName();

        $customer->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been removed.', ['name' => $name]),
        ]);

        return back();
    }

    /**
     * @return array{search: string, type: string}
     */
    private function filters(Request $request): array
    {
        $type = $request->string('type')->toString();

        return [
            'search' => $request->string('search')->trim()->toString(),
            'type' => in_array($type, CustomerType::values(), true) ? $type : '',
        ];
    }
}
