<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\ProductData;
use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Models\Product;
use App\Services\Products\CatalogueSync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ProductController extends Controller
{
    private const TILES_PER_PAGE = 24;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        $viewer = $request->user();
        $search = $request->string('search')->trim()->toString();
        $status = $this->status($request);

        $products = Product::query()
            ->when($search !== '', fn (Builder $query) => $query->search($search))
            ->when($status !== null, fn (Builder $query) => $query->ofStatus($status))
            ->orderBy('name')
            # Names are not unique, so name alone is an unstable sort and the pager repeats tiles.
            ->orderBy('id')
            ->paginate(self::TILES_PER_PAGE)
            ->withQueryString()
            ->through(ProductData::fromModel(...));

        return Inertia::render('admin/products/Index', [
            'products' => Inertia::scroll($products),
            'filters' => [
                'search' => $search,
                'status' => $status?->value ?? '',
            ],
            'statuses' => ProductStatus::options(),
            'counts' => $this->counts(),
            'can' => [
                'create' => $viewer->can('create', Product::class),
                'update' => $viewer->can('update', new Product),
                'delete' => $viewer->can('delete', new Product),
                'sync' => $viewer->can('create', Product::class)
                    && $viewer->can('update', new Product),
            ],
            'sync_configured' => filled(config('services.main_website.token')),
        ]);
    }

    private function status(Request $request): ?ProductStatus
    {
        return ProductStatus::tryFrom($request->string('status')->trim()->toString());
    }

    /**
     * Counts what the list can actually show, so soft-deleted rows are excluded. Most
     * `archived` products are also soft-deleted, which is why that tab reads near-empty.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counted = Product::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = ['all' => (int) $counted->sum()];

        foreach (ProductStatus::cases() as $status) {
            $counts[$status->value] = (int) $counted->get($status->value, 0);
        }

        return $counts;
    }

    public function create(): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('admin/products/Form', [
            'product' => null,
            'statuses' => ProductStatus::options(),
        ]);
    }

    public function edit(Product $product): Response
    {
        $this->authorize('update', $product);

        return Inertia::render('admin/products/Form', [
            'product' => ProductData::fromModel($product),
            'statuses' => ProductStatus::options(),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = new Product($request->safe()->only(['name', 'sku']));
        $product->status = $this->chosenStatus($request) ?? ProductStatus::Published;
        $product->created_by = $request->user()->id;

        if ($request->hasFile('image')) {
            $product->image_path = $request->file('image')
                ->store(Product::IMAGE_DIRECTORY, 'public');
        }

        $product->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been added.', ['name' => $product->name]),
        ]);

        return to_route('admin.products.index');
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $previousImage = $product->image_path;

        $product->fill($request->safe()->only(['name', 'sku']));

        # Absent means the form did not ask, never that the status should be reset - a
        # sync cannot clobber a status and neither may a partial form post.
        $product->status = $this->chosenStatus($request) ?? $product->status;

        if ($request->hasFile('image')) {
            $product->image_path = $request->file('image')
                ->store(Product::IMAGE_DIRECTORY, 'public');
        } elseif ($request->boolean('remove_image')) {
            $product->image_path = null;
        }

        $product->save();

        # Only a file this application stored: a synced product points at the website's own URL.
        if ($previousImage !== null
            && $previousImage !== $product->image_path
            && ! str_starts_with($previousImage, 'http')) {
            Storage::disk('public')->delete($previousImage);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been saved.', ['name' => $product->name]),
        ]);

        return to_route('admin.products.index');
    }

    private function chosenStatus(ProductRequest $request): ?ProductStatus
    {
        $status = $request->validated('status');

        return is_string($status) ? ProductStatus::from($status) : null;
    }

    public function sync(Request $request, CatalogueSync $sync): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $this->authorize('update', new Product);

        try {
            $summary = $sync->run($request->boolean('include_unpublished'));
        } catch (RuntimeException $exception) {
            Log::warning('Catalogue sync failed.', ['message' => $exception->getMessage()]);

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);

            return back();
        }

        $message = $summary['total'] === 0
            ? __('The website returned no products.')
            : __(':created added, :updated updated, :unchanged already current.', [
                'created' => $summary['created'],
                'updated' => $summary['updated'],
                'unchanged' => $summary['unchanged'],
            ]);

        if ($summary['removed'] > 0) {
            $message .= ' '.trans_choice(
                '{1} :count product is no longer on the website and has been removed.'
                    .'|[2,*] :count products are no longer on the website and have been removed.',
                $summary['removed'],
                ['count' => $summary['removed']],
            );
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $message,
        ]);

        return back();
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        $name = $product->name;

        $product->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been removed.', ['name' => $name]),
        ]);

        return back();
    }
}
