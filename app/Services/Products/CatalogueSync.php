<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Enums\ProductSource;
use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Pulls the product catalogue from the main website.
 *
 * The website owns the catalogue; this application keeps a copy of the few
 * things the showroom floor needs - a picture, a code, a model number and a
 * name - so a salesperson can work from a tablet without loading the
 * storefront.
 *
 * Matching is on `external_id`, the website's own product id. That is the only
 * key that survives a rename, and it is what stops a second run creating a
 * second copy of everything.
 */
class CatalogueSync
{
    /** Pages to walk before giving up, so a broken pager cannot loop forever. */
    private const MAX_PAGES = 200;

    /**
     * @return array{created: int, updated: int, unchanged: int, removed: int, total: int}
     */
    public function run(bool $includeUnpublished = false): array
    {
        $base = rtrim((string) config('services.main_website.url'), '/');
        $token = config('services.main_website.token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException(
                'No sync token is configured. Set MAIN_WEBSITE_SYNC_TOKEN to the value the website expects.'
            );
        }

        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'total' => 0];
        $page = 1;

        /** @var array<int, true> The website ids this run actually saw. */
        $seen = [];

        do {
            $response = Http::withHeaders(['X-Catalogue-Token' => $token])
                ->acceptJson()
                ->timeout((int) config('services.main_website.timeout', 20))
                /* Retried only when retrying could help: a dropped
                   connection or the website falling over. A rejected token or
                   a 404 will say the same thing three times. */
                ->retry(2, 250, function (Throwable $exception): bool {
                    return $exception instanceof ConnectionException
                        || ($exception instanceof RequestException
                            && $exception->response->serverError());
                }, throw: false)
                ->get($base.'/api/catalogue/products', [
                    'page' => $page,
                    'per_page' => 200,
                    'include_unpublished' => $includeUnpublished ? 1 : 0,
                ]);

            if ($response->status() === 401) {
                throw new RuntimeException(
                    'The website rejected the sync token. Check that both applications hold the same value.'
                );
            }

            if ($response->failed()) {
                throw new RuntimeException(
                    "The website returned {$response->status()} when asked for the catalogue."
                );
            }

            $rows = $response->json('data') ?? [];

            /* One transaction per page rather than one for the whole run: a
               catalogue of thousands should not hold a write lock throughout,
               and a page that lands is a page worth keeping. */
            DB::transaction(function () use ($rows, &$summary, &$seen) {
                foreach ($rows as $row) {
                    $this->apply($row, $summary, $seen);
                }
            });

            $lastPage = (int) ($response->json('meta.last_page') ?? 1);
            $page++;
        } while ($page <= $lastPage && $page <= self::MAX_PAGES);

        /* Only after every page landed. A run cut short by `MAX_PAGES` has not
           seen the whole catalogue, and pruning against a partial list would
           soft-delete products that are simply on a page nobody read. */
        if ($page > $lastPage) {
            $summary['removed'] = $this->prune($seen);
        }

        return $summary;
    }

    /**
     * Soft-deletes synced products the website no longer offers.
     *
     * A product taken down or unpublished on the website should leave the
     * showroom floor too. Soft-deleted rather than removed: a product is
     * attached to the visits it was shown in, and those still need to resolve
     * it. If it reappears on the website, `apply()` restores it.
     *
     * Products added here by hand are nobody's business but the person who
     * typed them in, so they are never touched.
     *
     * @param  array<int, true>  $seen
     */
    private function prune(array $seen): int
    {
        /* An empty feed is far more likely to be a broken website than a
           catalogue that genuinely sells nothing, and acting on it would clear
           the floor. Left alone for somebody to look at. */
        if ($seen === []) {
            return 0;
        }

        $withdrawn = Product::query()
            ->where('source', ProductSource::Website)
            ->whereNotNull('external_id')
            ->whereNotIn('external_id', array_keys($seen));

        /* Status before the soft delete, in that order: once the rows carry a
           `deleted_at` the same builder no longer finds them, and a row that
           has left the floor while still reading "Published" is the exact
           disagreement this column was added to prevent.

           This is the one place a locally-set `Inactive` does not survive, and
           it is not the sync overruling a person: the product is gone from the
           website, the row is being soft-deleted either way, and the two
           columns have to agree about it. */
        $withdrawn->clone()->update(['status' => ProductStatus::Archived->value]);

        return $withdrawn->delete();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{created: int, updated: int, unchanged: int, removed: int, total: int}  $summary
     * @param  array<int, true>  $seen
     */
    private function apply(array $row, array &$summary, array &$seen): void
    {
        $externalId = $row['id'] ?? null;
        $name = trim((string) ($row['name'] ?? ''));

        /* A product with no id cannot be matched on the next run, and one with
           no name has nothing to show on a tile. Skipped rather than imported
           as a blank row somebody then has to find and delete. */
        if (! is_numeric($externalId) || $name === '') {
            return;
        }

        /* Recorded before anything else can go wrong with the row: a product
           the website still offers must not be pruned just because this run
           declined to write it. */
        $seen[(int) $externalId] = true;

        $summary['total']++;

        $product = Product::withTrashed()
            ->where('external_id', (int) $externalId)
            ->first();

        $attributes = [
            'name' => $name,
            'sku' => $this->sku($row['sku'] ?? null, (int) $externalId),
            'model_number' => $this->modelNumber($row),
            'image_path' => $row['image_url'] ?? null,
        ];

        if ($product === null) {
            /* A product somebody typed in here first, matched on its SKU, is
               adopted rather than duplicated - the same steel sheet should not
               appear twice because it was entered before the sync existed. */
            $product = $attributes['sku'] === null
                ? null
                : Product::withTrashed()->where('sku', $attributes['sku'])->first();
        }

        $withdrawn = $this->readsAsWithdrawn($row);

        if ($product === null) {
            Product::query()->forceCreate([
                ...$attributes,
                'status' => $this->status($row, null) ?? ProductStatus::Published,
                'source' => ProductSource::Website,
                'external_id' => (int) $externalId,
                'synced_at' => now(),
                /* A row that arrives already withdrawn is created withdrawn. It
                   exists so the visits naming it still resolve, not so it can
                   appear on a tile. */
                'deleted_at' => $withdrawn ? now() : null,
            ]);

            $summary['created']++;

            return;
        }

        $product->fill($attributes);
        $product->source = ProductSource::Website;
        $product->external_id = (int) $externalId;

        /* Null means the feed said nothing this run that should move the
           status, so whatever is on the row stays there. Assigning the null
           anyway is the bug this guard exists to prevent. */
        $status = $this->status($row, $product);

        if ($status !== null) {
            $product->status = $status;
        }

        $changed = $product->isDirty(['name', 'sku', 'model_number', 'image_path', 'status']);

        $product->synced_at = now();

        if ($withdrawn) {
            /* Marked gone upstream while still live here. Soft-deleted so the
               row leaves the floor the same way `prune()` would take it off,
               rather than lingering with an `Archived` badge on a live tile. */
            if (! $product->trashed()) {
                $product->deleted_at = now();
                $changed = true;
            }
        } elseif ($product->trashed()) {
            /* A product removed here and still on the website comes back: the
               website is the catalogue, and a deletion here was a local
               tidy-up. */
            $product->deleted_at = null;
            $changed = true;
        }

        $product->save();

        $changed ? $summary['updated']++ : $summary['unchanged']++;
    }

    /**
     * The status this row should carry, or null to leave the local one alone.
     *
     * The website owns three of the four states and knows nothing of the
     * fourth. `Inactive` is set by a person standing on the floor who has
     * decided a product is not worth showing this month, and the website has no
     * field that could ever mean that - so there is nothing it could say that
     * should overrule them. A re-sync leaves an `Inactive` row `Inactive`, which
     * is why this reads the existing product rather than the payload alone.
     *
     * The null return is the other half of the design. A feed carrying none of
     * these fields - which is what an endpoint that has not been updated sends
     * - is not asserting that everything is a draft; it is a feed with nothing
     * to say. Mapping that silence onto `Draft` would take the entire floor
     * offline on the first run against such an endpoint.
     *
     * @param  array<string, mixed>  $row
     */
    private function status(array $row, ?Product $product): ?ProductStatus
    {
        if ($this->readsAsWithdrawn($row)) {
            return ProductStatus::Archived;
        }

        if ($product?->status === ProductStatus::Inactive) {
            return null;
        }

        $published = $this->readsAsBoolean($row['is_published'] ?? null);

        if ($published !== null) {
            return $published ? ProductStatus::Published : ProductStatus::Draft;
        }

        /* Nothing left in the payload to go on. */

        if ($product === null) {
            /* The feed returned it, so it is a product the website sells. */
            return ProductStatus::Published;
        }

        /* A row the sync itself archived, back on the feed. It is un-deleted
           just below, and leaving it `Archived` would put the status and the
           soft delete straight back into disagreement. */
        if ($product->status === ProductStatus::Archived || $product->trashed()) {
            return ProductStatus::Published;
        }

        return null;
    }

    /**
     * Whether the website has taken this product down.
     *
     * `deleted_at` is what the endpoint sends, and it only arrives filled when
     * the caller asked for withdrawn rows. The other keys cost nothing to
     * accept and cover a feed that signals removal with a flag rather than a
     * timestamp, which would otherwise be read as a product still on sale.
     *
     * @param  array<string, mixed>  $row
     */
    private function readsAsWithdrawn(array $row): bool
    {
        if (($row['deleted_at'] ?? null) !== null) {
            return true;
        }

        foreach (['is_deleted', 'is_removed', 'removed'] as $key) {
            if ($this->readsAsBoolean($row[$key] ?? null) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * A flag as a boolean, or null when the payload carried none.
     *
     * The distinction is the point: to `status()`, `false` and "absent" mean
     * opposite things, and a plain cast would flatten a missing key into a
     * definite no.
     */
    private function readsAsBoolean(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        /* JSON booleans arrive as booleans, but a feed serialising straight off
           a database column has been seen to send 1, "1" or "true". */
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    /**
     * The model number the manufacturer stamped on it, or null.
     *
     * The endpoint sends `model_number`; `model` is still accepted because the
     * catalogue has been seen to use either, and a feed that answers to only
     * one should not silently import blanks. Run through the same placeholder
     * check as the SKU: a literal "null" read off a tile is quoted to a
     * customer as though it meant something.
     *
     * @param  array<string, mixed>  $row
     */
    private function modelNumber(array $row): ?string
    {
        $value = $row['model_number'] ?? $row['model'] ?? null;
        $model = is_string($value) ? trim($value) : '';

        return $model === '' || $this->readsAsBlank($model) ? null : $model;
    }

    /**
     * The SKU, or null when the website has none.
     *
     * A blank is not stored as an empty string: the column is unique, and the
     * second uncoded product would collide with the first. A duplicate coming
     * from the website is dropped for the same reason - two rows cannot hold
     * one SKU, and the website is where that needs fixing.
     */
    private function sku(mixed $value, int $externalId): ?string
    {
        $sku = is_string($value) ? trim($value) : '';

        if ($sku === '' || $this->readsAsBlank($sku)) {
            return null;
        }

        $taken = Product::withTrashed()
            ->where('sku', $sku)
            ->where('external_id', '!=', $externalId)
            ->whereNotNull('external_id')
            ->exists();

        return $taken ? null : $sku;
    }

    /**
     * Whether a SKU is a placeholder standing in for no SKU at all.
     *
     * The website holds hundreds of products whose code is the literal string
     * "null" - a value that was written out rather than left empty. Stored as
     * it stands, a salesperson reads "null" off the tile and quotes it. These
     * are the words that mean absent, not a code somebody would ever use.
     */
    private function readsAsBlank(string $sku): bool
    {
        return in_array(
            mb_strtolower($sku),
            ['null', 'nil', 'none', 'n/a', 'na', '-', '--', 'undefined'],
            strict: true
        );
    }
}
