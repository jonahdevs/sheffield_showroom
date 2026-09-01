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
 * Pulls the product catalogue from the main website, matching on `external_id` - the
 * only key that survives a rename, and what stops a second run duplicating everything.
 */
class CatalogueSync
{
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

            DB::transaction(function () use ($rows, &$summary, &$seen) {
                foreach ($rows as $row) {
                    $this->apply($row, $summary, $seen);
                }
            });

            $lastPage = (int) ($response->json('meta.last_page') ?? 1);
            $page++;
        } while ($page <= $lastPage && $page <= self::MAX_PAGES);

        # Only once every page landed. A run cut short by `MAX_PAGES` has not seen the
        # whole catalogue, and pruning against a partial list would soft-delete products
        # that are merely on a page nobody read.
        if ($page > $lastPage) {
            $summary['removed'] = $this->prune($seen);
        }

        return $summary;
    }

    /**
     * Soft-deleted, never removed: a product is attached to the visits it was shown in.
     * Products added here by hand are never touched.
     *
     * @param  array<int, true>  $seen
     */
    private function prune(array $seen): int
    {
        # An empty feed is a broken website far more often than a catalogue that sells
        # nothing, and acting on it would clear the floor.
        if ($seen === []) {
            return 0;
        }

        $withdrawn = Product::query()
            ->where('source', ProductSource::Website)
            ->whereNotNull('external_id')
            ->whereNotIn('external_id', array_keys($seen));

        # Status before the soft delete, in that order: once the rows carry a
        # `deleted_at` the same builder no longer finds them.
        #
        # The one place a locally-set `Inactive` does not survive - the row is being
        # soft-deleted either way, and the two columns have to agree.
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

        if (! is_numeric($externalId) || $name === '') {
            return;
        }

        # Recorded before anything else can go wrong with the row: a product the
        # website still offers must not be pruned because this run declined to write it.
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
            # A hand-entered product matching on SKU is adopted, not duplicated.
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
                # Created withdrawn, so the visits naming it still resolve.
                'deleted_at' => $withdrawn ? now() : null,
            ]);

            $summary['created']++;

            return;
        }

        $product->fill($attributes);
        $product->source = ProductSource::Website;
        $product->external_id = (int) $externalId;

        # Null means "leave the local status alone". Assigning it anyway is the bug
        # this guard exists to prevent.
        $status = $this->status($row, $product);

        if ($status !== null) {
            $product->status = $status;
        }

        $changed = $product->isDirty(['name', 'sku', 'model_number', 'image_path', 'status']);

        $product->synced_at = now();

        if ($withdrawn) {
            if (! $product->trashed()) {
                $product->deleted_at = now();
                $changed = true;
            }
        } elseif ($product->trashed()) {
            # The website is the catalogue, so a local deletion was a tidy-up and the
            # product comes back.
            $product->deleted_at = null;
            $changed = true;
        }

        $product->save();

        $changed ? $summary['updated']++ : $summary['unchanged']++;
    }

    /**
     * The status this row should carry, or null to leave the local one alone. Do not
     * collapse that null into a default.
     *
     * `Inactive` is local-only - the website has no field that could mean it - which is
     * why this reads the existing product and not the payload alone. And a payload
     * missing `is_published` / `deleted_at` is a feed with nothing to say, not a feed
     * asserting Draft; mapping that silence onto `Draft` takes the whole floor offline.
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

        # Nothing left in the payload to go on. A new row lands Published; an existing
        # one keeps what it has.
        if ($product === null) {
            return ProductStatus::Published;
        }

        # A row the sync itself archived, back on the feed. It is un-deleted just below,
        # and leaving it `Archived` would put status and soft delete back in disagreement.
        if ($product->status === ProductStatus::Archived || $product->trashed()) {
            return ProductStatus::Published;
        }

        return null;
    }

    /**
     * The extra keys cover a feed that signals removal with a flag rather than a
     * timestamp, which would otherwise read as a product still on sale.
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
     * Null when the payload carried no such key. To `status()`, `false` and "absent"
     * mean opposite things, and a plain cast would flatten a missing key into a no.
     */
    private function readsAsBoolean(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        # A feed serialising straight off a database column sends 1, "1" or "true".
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Both `model_number` and `model` are accepted - the catalogue uses either.
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
     * Null, never an empty string: the column is unique, so a second uncoded product
     * would collide with the first. A duplicate from the website is dropped for the
     * same reason - the website is where that needs fixing.
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
     * The website holds hundreds of products whose code is the literal string "null".
     * Stored as it stands, a salesperson reads it off the tile and quotes it.
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
