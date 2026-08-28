<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\ProductData;
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

/**
 * The catalogue as the showroom floor needs it: a picture, a code and a name.
 */
class ProductController extends Controller
{
    /**
     * Tiles fetched per scroll. Enough to fill the widest grid several rows
     * deep, so the next page is already on its way before the last row shows.
     */
    private const TILES_PER_PAGE = 24;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        $viewer = $request->user();
        $search = $request->string('search')->trim()->toString();

        $products = Product::query()
            ->when($search !== '', fn (Builder $query) => $query->search($search))
            ->orderBy('name')
            /* The catalogue holds products that share a name to the letter, so
               a sort on name alone puts them in no fixed order. Page two would
               then repeat a tile page one already showed, or skip it. */
            ->orderBy('id')
            ->paginate(self::TILES_PER_PAGE)
            ->withQueryString()
            ->through(ProductData::fromModel(...));

        return Inertia::render('admin/products/Index', [
            /* Appended to what the page already holds as the floor scrolls,
               rather than replacing it. `reset` on the client is what turns a
               new search back into a fresh list. */
            'products' => Inertia::scroll($products),
            'filters' => ['search' => $search],
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

    public function create(): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('admin/products/Form', ['product' => null]);
    }

    public function edit(Product $product): Response
    {
        $this->authorize('update', $product);

        return Inertia::render('admin/products/Form', [
            'product' => ProductData::fromModel($product),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = new Product($request->safe()->only(['name', 'sku']));
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

        if ($request->hasFile('image')) {
            $product->image_path = $request->file('image')
                ->store(Product::IMAGE_DIRECTORY, 'public');
        } elseif ($request->boolean('remove_image')) {
            $product->image_path = null;
        }

        $product->save();

        /* Only after the row is saved, and only a file this application put
           there: a synced product points at the website's own URL, which is
           not ours to delete. */
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

    /**
     * Pulls the catalogue from the main website.
     *
     * Runs inline rather than on a queue: somebody pressed a button and is
     * waiting to see what changed, and a few hundred rows over a local
     * connection is a second or two.
     */
    public function sync(Request $request, CatalogueSync $sync): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $this->authorize('update', new Product);

        try {
            $summary = $sync->run($request->boolean('include_unpublished'));
        } catch (RuntimeException $exception) {
            /* The message is written for whoever pressed the button, so it is
               shown as it stands; the trace goes to the log for whoever has
               to fix it. */
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

        /* Only mentioned when it happened. A count of nothing removed on every
           successful sync is noise that trains people to stop reading. */
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

    /**
     * Soft deleted, and the image is left alone. A product will be attached to
     * the visits it was shown in, and those still want a picture.
     */
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
