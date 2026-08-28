<script setup lang="ts">
import { Head, InfiniteScroll, Link, router } from '@inertiajs/vue3';
import {
    ImageOff,
    Loader2,
    Pencil,
    Plus,
    RefreshCw,
    Search,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import type { Paginator } from '@/components/TablePagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Skeleton } from '@/components/ui/skeleton';
import { useFilters } from '@/composables/useFilters';
import { dashboard } from '@/routes';
import { create, destroy, edit, index, sync } from '@/routes/admin/products';

interface Paginated<T> extends Paginator {
    data: T[];
}

const props = defineProps<{
    products: Paginated<App.Data.ProductData>;
    filters: { search: string };
    can: { create: boolean; update: boolean; delete: boolean; sync: boolean };
    /** False when no token is set, which is the usual reason a sync fails. */
    sync_configured: boolean;
}>();

const { filters, hasFilters, processing, clear } = useFilters({
    url: index.url(),
    initial: { search: props.filters.search ?? '' },
    blank: { search: '' },
    only: ['products', 'filters'],
    /* Without this the tiles the search just found would be appended to the
       ones it was meant to replace. */
    reset: ['products'],
});

// -----------------------------------------------------------------------------
// Pulling the catalogue from the main website
// -----------------------------------------------------------------------------

const syncing = ref(false);

/**
 * Asked before it runs. A fetch rewrites every tile the website owns and takes
 * down the ones it has dropped, which is not what somebody reaching for the
 * refresh icon out of habit expects.
 */
const confirmingSync = ref(false);

/**
 * `preserveScroll` so the grid does not jump, and no `preserveState`: the
 * whole point is to come back with the rows the website just gave us.
 */
function pullFromWebsite() {
    router.post(
        sync().url,
        {},
        {
            preserveScroll: true,
            onStart: () => (syncing.value = true),
            onFinish: () => {
                syncing.value = false;
                confirmingSync.value = false;
            },
        },
    );
}

const deleting = ref<App.Data.ProductData | null>(null);

function confirmDelete() {
    const product = deleting.value;

    if (product === null) {
        return;
    }

    router.delete(destroy(product.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            deleting.value = null;
        },
    });
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Products' },
        ],
    },
});
</script>

<!--
  The catalogue as the floor needs it. A grid rather than a table because the
  picture is the point: a salesperson recognises the product before they read
  the code, which is the opposite of how they find a customer.
-->
<template>
    <Head title="Products" />

    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-start gap-4">
            <div class="flex min-w-0 flex-1 flex-col gap-2">
                <h1 class="text-2xl leading-tight">Products</h1>
                <p class="text-sm text-muted-foreground">
                    What the showroom has on the floor.
                </p>
            </div>

            <div class="flex items-center gap-2.5">
                <Button
                    v-if="props.can.sync"
                    variant="quiet"
                    :disabled="syncing || !props.sync_configured"
                    :title="
                        props.sync_configured
                            ? 'Fetch names, SKUs and pictures from the main website.'
                            : 'No sync token is configured for the main website.'
                    "
                    data-test="sync-products"
                    @click="confirmingSync = true"
                >
                    <RefreshCw :class="syncing ? 'animate-spin' : ''" />
                    {{ syncing ? 'Fetching...' : 'Fetch products' }}
                </Button>

                <Button
                    v-if="props.can.create"
                    as-child
                    data-test="new-product"
                >
                    <Link :href="create().url">
                        <Plus />
                        New product
                    </Link>
                </Button>
            </div>
        </div>

        <Card class="min-w-0 gap-0 overflow-hidden p-0">
            <div
                class="flex flex-wrap items-center gap-2.5 border-b border-border px-5 py-3.5"
            >
                <InputGroup class="w-full max-w-80">
                    <InputGroupInput
                        v-model="filters.search"
                        type="search"
                        placeholder="Search name or SKU..."
                        aria-label="Search products"
                        data-test="product-search"
                    />

                    <InputGroupAddon>
                        <Search />
                    </InputGroupAddon>
                </InputGroup>

                <Button
                    v-if="hasFilters"
                    variant="link"
                    size="sm"
                    class="px-1 sm:ml-auto"
                    data-test="product-clear"
                    @click="clear"
                >
                    Clear
                </Button>
            </div>

            <!-- Ahead of the empty branch on purpose: mid-reload nobody knows
                 what is coming back, and flashing "no products" over tiles
                 about to land reads as broken. -->
            <div
                v-if="processing"
                class="grid gap-4 p-5 @2xl/page:grid-cols-3 @5xl/page:grid-cols-4 @6xl/page:grid-cols-5"
                aria-hidden="true"
            >
                <div v-for="tile in 8" :key="tile" class="flex flex-col gap-3">
                    <Skeleton class="aspect-4/3 w-full rounded-lg" />
                    <Skeleton class="h-3 w-3/4" />
                    <Skeleton class="h-3 w-1/3" />
                </div>
            </div>

            <div
                v-else-if="props.products.data.length === 0"
                class="m-5 rounded-lg bg-muted/60 p-6 text-center"
                data-test="products-empty"
            >
                <p class="text-sm text-muted-foreground">
                    {{
                        hasFilters
                            ? 'No product matches that search.'
                            : 'No products have been added yet.'
                    }}
                </p>
                <Button
                    v-if="!hasFilters && props.can.create"
                    as-child
                    variant="quiet"
                    size="sm"
                    class="mt-3.5"
                >
                    <Link :href="create().url">
                        <Plus />
                        Add the first one
                    </Link>
                </Button>
            </div>

            <!-- `data` names the prop the server marked as scrollable, and the
                 component takes it from there: it watches the end of the grid
                 and asks for the next page before the floor reaches it.

                 The grid classes belong on the component rather than on a div
                 inside it. InfiniteScroll treats its own direct children as
                 the items and tags each with the page it arrived on, so a
                 wrapper in between leaves it holding one child forever. -->
            <InfiniteScroll
                v-else
                data="products"
                class="grid gap-4 p-5 @2xl/page:grid-cols-3 @5xl/page:grid-cols-4 @6xl/page:grid-cols-5"
            >
                <div
                    v-for="product in props.products.data"
                    :key="product.id"
                    class="group flex flex-col overflow-hidden rounded-xl border border-border"
                    :data-test="`product-${product.id}`"
                >
                    <div
                        class="flex aspect-4/3 items-center justify-center overflow-hidden bg-muted"
                    >
                        <img
                            v-if="product.image_url"
                            :src="product.image_url"
                            :alt="product.name"
                            loading="lazy"
                            class="size-full object-contain"
                        />
                        <ImageOff
                            v-else
                            class="size-7 text-faint"
                            aria-hidden="true"
                        />
                    </div>

                    <div class="flex flex-1 flex-col gap-1 p-3.5">
                        <p class="text-xs font-bold" :title="product.name">
                            {{ product.name }}
                        </p>
                        <p
                            class="font-mono text-xs text-faint"
                            :class="product.sku ? '' : 'italic'"
                        >
                            {{ product.sku ?? 'No SKU' }}
                        </p>

                        <div class="mt-auto flex items-center gap-1.5 pt-3">
                            <Badge
                                v-if="product.is_synced"
                                variant="secondary"
                                title="Kept in step with the main website. Changes made here would be overwritten."
                            >
                                Website
                            </Badge>

                            <div class="ml-auto flex items-center gap-1">
                                <Button
                                    v-if="props.can.update"
                                    as-child
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="`Edit ${product.name}`"
                                    :data-test="`edit-${product.id}`"
                                >
                                    <Link :href="edit(product.id).url">
                                        <Pencil />
                                    </Link>
                                </Button>

                                <Button
                                    v-if="props.can.delete"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Remove ${product.name}`"
                                    :data-test="`delete-${product.id}`"
                                    @click="deleting = product"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Where the pager used to sit: a line saying how far down
                     the catalogue you are, so an endless grid still answers
                     "how many of these are there?". `next` rather than
                     `loading`, which the component only renders mid-request. -->
                <template #next="{ loading, hasMore }">
                    <div
                        class="flex items-center justify-center gap-2 border-t border-border px-5 py-4 text-xs text-faint tabular-nums"
                        data-test="products-footer"
                    >
                        <template v-if="loading">
                            <Loader2 class="size-3.5 animate-spin" />
                            Loading more products...
                        </template>
                        <template v-else>
                            Showing {{ props.products.data.length }} of
                            {{ props.products.total }} products{{
                                hasMore ? ' - scroll for more' : ''
                            }}
                        </template>
                    </div>
                </template>
            </InfiniteScroll>
        </Card>
    </div>

    <Dialog
        :open="confirmingSync"
        @update:open="!$event && (confirmingSync = false)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Fetch products from the website?</DialogTitle>
                <DialogDescription>
                    Every product the website owns is rewritten with the name,
                    code and picture it holds, and any it no longer sells comes
                    off the floor. Products added here by hand are left alone.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter>
                <Button
                    variant="quiet"
                    :disabled="syncing"
                    @click="confirmingSync = false"
                >
                    Cancel
                </Button>
                <Button
                    :disabled="syncing"
                    data-test="confirm-sync-products"
                    @click="pullFromWebsite"
                >
                    <RefreshCw :class="syncing ? 'animate-spin' : ''" />
                    {{ syncing ? 'Fetching...' : 'Fetch products' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="deleting !== null"
        @update:open="!$event && (deleting = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Remove {{ deleting?.name }}?</DialogTitle>
                <DialogDescription>
                    It comes off the catalogue. Nothing it is already attached
                    to is lost, and the record can be brought back.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter>
                <Button variant="quiet" @click="deleting = null">Cancel</Button>
                <Button
                    variant="destructive"
                    data-test="confirm-delete-product"
                    @click="confirmDelete"
                >
                    Remove product
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
