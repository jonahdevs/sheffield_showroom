<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Building2,
    Pencil,
    Plus,
    Search,
    Trash2,
    Upload,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ExportMenu from '@/components/ExportMenu.vue';
import InputError from '@/components/InputError.vue';
import PageSizeSelect from '@/components/PageSizeSelect.vue';
import TablePagination from '@/components/TablePagination.vue';
import type { Paginator } from '@/components/TablePagination.vue';
import TableSkeleton from '@/components/TableSkeleton.vue';
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
import { useFilters } from '@/composables/useFilters';
import { confirmDelete } from '@/lib/confirm';
import { dashboard } from '@/routes';
import {
    create,
    destroy,
    edit,
    exportMethod,
    importMethod,
    index,
} from '@/routes/admin/customers';

interface Paginated<T> extends Paginator {
    data: T[];
}

const props = defineProps<{
    customers: Paginated<App.Data.CustomerRowData>;
    filters: { search: string; type: string };
    types: { value: string; label: string }[];
    page_sizes: number[];
    formats: string[];
    counts: { all: number; individual: number; company: number };
    can: {
        create: boolean;
        update: boolean;
        delete: boolean;
        export: boolean;
        import: boolean;
    };
}>();

const { filters, query, hasFilters, processing, apply, clear } = useFilters({
    url: index.url(),
    initial: {
        search: props.filters.search ?? '',
        type: props.filters.type === '' ? 'all' : props.filters.type,
    },
    blank: { search: '', type: 'all' },
    only: ['customers', 'filters'],
});

const tabs = computed(() => [
    { value: 'all', label: 'All', count: props.counts.all },
    ...props.types.map((type) => ({
        value: type.value,
        label: type.label === 'Individual' ? 'Individuals' : 'Companies',
        count:
            type.value === 'individual'
                ? props.counts.individual
                : props.counts.company,
    })),
]);

const importing = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);

const importForm = useForm<{ file: File | null }>({ file: null });

function chooseFile(event: Event) {
    const [file] = (event.target as HTMLInputElement).files ?? [];

    importForm.file = file ?? null;
    importForm.clearErrors('file');
}

function sendImport() {
    importForm.post(importMethod().url, {
        preserveScroll: true,
        onSuccess: () => {
            importing.value = false;
            importForm.reset();

            /* The input keeps its chosen file across a form reset, so picking
               the same file again would fire no change event at all. */
            if (fileInput.value !== null) {
                fileInput.value.value = '';
            }
        },
    });
}

async function removeCustomer(customer: App.Data.CustomerRowData) {
    if (!(await confirmDelete())) {
        return;
    }

    router.delete(destroy(customer.id).url, { preserveScroll: true });
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Customers' },
        ],
    },
});
</script>

<template>
    <Head title="Customers" />

    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-start gap-4">
            <div class="flex min-w-0 flex-1 flex-col gap-2">
                <h1 class="text-2xl leading-tight">Customers</h1>
                <p class="text-sm text-muted-foreground">
                    The people and companies who visit the showroom.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <Button
                    v-if="props.can.import"
                    variant="outline"
                    data-test="import-customers"
                    @click="importing = true"
                >
                    <Upload />
                    Import
                </Button>

                <ExportMenu
                    v-if="props.can.export"
                    :url="exportMethod().url"
                    :query="query"
                    :formats="props.formats"
                    name="customers"
                />

                <Button
                    v-if="props.can.create"
                    as-child
                    data-test="new-customer"
                >
                    <Link :href="create().url">
                        <Plus />
                        New customer
                    </Link>
                </Button>
            </div>
        </div>

        <Card class="min-w-0 gap-0 overflow-hidden p-0">
            <div
                class="flex flex-wrap items-center gap-1 border-b border-border px-5 pt-3.5"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    class="-mb-px flex items-center gap-2 border-b-2 px-3 pb-3 text-xs font-bold transition-colors"
                    :class="
                        filters.type === tab.value
                            ? 'border-primary text-primary'
                            : 'border-transparent text-faint hover:text-foreground'
                    "
                    :data-test="`tab-${tab.value}`"
                    @click="apply({ type: tab.value })"
                >
                    {{ tab.label }}
                    <span class="text-faint tabular-nums">{{ tab.count }}</span>
                </button>
            </div>

            <div
                class="flex flex-wrap items-center gap-2.5 border-b border-border px-5 py-3.5"
            >
                <InputGroup class="w-full max-w-80">
                    <InputGroupInput
                        v-model="filters.search"
                        type="search"
                        placeholder="Search name, company, phone or email..."
                        aria-label="Search customers"
                        data-test="customer-search"
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
                    data-test="customer-clear"
                    @click="clear"
                >
                    Clear
                </Button>
            </div>

            <!-- Ahead of the empty branch on purpose: reordering these flashes
                 "no customers yet" over rows that are about to land. -->
            <TableSkeleton
                v-if="processing"
                class="px-5 py-2"
                :rows="Math.min(props.customers.per_page, 10)"
                :columns="5"
            />

            <div
                v-else-if="props.customers.data.length === 0"
                class="m-5 rounded-lg bg-muted/60 p-6 text-center"
                data-test="customers-empty"
            >
                <p class="text-sm text-muted-foreground">
                    {{
                        hasFilters
                            ? 'No customer matches that search.'
                            : 'No customers have been recorded yet.'
                    }}
                </p>
                <Button
                    v-if="!hasFilters && props.can.create"
                    as-child
                    variant="outline"
                    size="sm"
                    class="mt-3.5"
                >
                    <Link :href="create().url">
                        <Plus />
                        Add the first one
                    </Link>
                </Button>
            </div>

            <template v-else>
                <div class="overflow-x-auto">
                    <div
                        class="grid min-w-[880px] grid-cols-[minmax(0,1.5fr)_minmax(0,1.15fr)_minmax(0,0.6fr)_minmax(0,0.85fr)_minmax(0,0.4fr)_minmax(0,0.7fr)_84px] items-center gap-4 border-b border-border bg-muted/50 px-5 py-3.5 text-xs font-semibold"
                    >
                        <span>Customer name</span>
                        <span>Company</span>
                        <span>Type</span>
                        <span>Phone</span>
                        <span>Visits</span>
                        <span>Last visit</span>
                        <span class="sr-only">Actions</span>
                    </div>

                    <div class="divide-y divide-divider">
                        <div
                            v-for="customer in props.customers.data"
                            :key="customer.id"
                            class="grid min-w-[880px] grid-cols-[minmax(0,1.5fr)_minmax(0,1.15fr)_minmax(0,0.6fr)_minmax(0,0.85fr)_minmax(0,0.4fr)_minmax(0,0.7fr)_84px] items-center gap-4 px-5 py-3"
                            :data-test="`customer-${customer.id}`"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-faint"
                                    aria-hidden="true"
                                >
                                    <Building2
                                        v-if="customer.type === 'company'"
                                        class="size-4"
                                    />
                                    <UserRound v-else class="size-4" />
                                </span>

                                <div class="min-w-0">
                                    <p
                                        class="truncate text-xs font-bold"
                                        :class="
                                            customer.name ? '' : 'text-faint'
                                        "
                                        :title="customer.name ?? undefined"
                                    >
                                        {{ customer.name ?? '--' }}
                                    </p>
                                    <p
                                        v-if="customer.email"
                                        class="mt-0.5 truncate text-xs text-faint"
                                        :title="customer.email"
                                    >
                                        {{ customer.email }}
                                    </p>
                                </div>
                            </div>

                            <span
                                class="truncate text-xs"
                                :class="
                                    customer.company_name ? '' : 'text-faint'
                                "
                                :title="customer.company_name ?? undefined"
                            >
                                {{ customer.company_name ?? '--' }}
                            </span>

                            <span
                                class="w-fit shrink-0 rounded border px-1.5 py-0.5 text-[0.6875rem] text-faint"
                            >
                                {{ customer.type_label }}
                            </span>

                            <span class="truncate text-xs tabular-nums">
                                {{ customer.phone }}
                            </span>

                            <span
                                class="text-xs tabular-nums"
                                :class="
                                    customer.visits_count > 0
                                        ? 'font-bold'
                                        : 'text-faint'
                                "
                            >
                                {{
                                    customer.visits_count > 0
                                        ? customer.visits_count
                                        : '--'
                                }}
                            </span>

                            <span
                                class="truncate text-xs text-faint tabular-nums"
                            >
                                {{ customer.last_visit ?? '--' }}
                            </span>

                            <div class="flex items-center justify-end gap-1">
                                <Button
                                    v-if="props.can.update"
                                    as-child
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="`Edit ${customer.display_name}`"
                                    :data-test="`edit-${customer.id}`"
                                >
                                    <Link :href="edit(customer.id).url">
                                        <Pencil />
                                    </Link>
                                </Button>

                                <Button
                                    v-if="props.can.delete"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Remove ${customer.display_name}`"
                                    :data-test="`delete-${customer.id}`"
                                    @click="removeCustomer(customer)"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <TablePagination
                    class="border-t border-border px-5 py-4"
                    :meta="props.customers"
                    label="customers"
                >
                    <PageSizeSelect
                        :sizes="props.page_sizes"
                        :current="props.customers.per_page"
                    />
                </TablePagination>
            </template>
        </Card>
    </div>

    <Dialog v-model:open="importing">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Import customers</DialogTitle>
                <DialogDescription>
                    A CSV or Excel file with a heading row. Rows are matched on
                    the telephone number, so sending the same file twice
                    corrects those customers rather than filing them again.
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-col gap-3.5">
                <div
                    class="flex flex-col gap-1.5 rounded-lg bg-muted/60 p-3.5 text-xs"
                >
                    <span class="font-bold">Columns</span>
                    <span class="text-faint">
                        Name and Phone are required. Type is
                        <em>individual</em> or <em>company</em>, and a company
                        row needs a Company. Industry, Email, ID number, Street
                        address, Area, City, County, Postal code and Country are
                        optional, and a blank cell leaves what is on record
                        alone.
                    </span>
                    <span v-if="props.can.export" class="text-faint">
                        An export of this list is a working template.
                    </span>
                </div>

                <input
                    id="import-file"
                    ref="fileInput"
                    type="file"
                    accept=".csv,.txt,.xlsx,.xls"
                    class="sr-only"
                    data-test="import-file"
                    @change="chooseFile"
                />

                <div class="flex flex-wrap items-center gap-2.5">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-test="choose-import-file"
                        @click="fileInput?.click()"
                    >
                        <Upload />
                        {{
                            importForm.file ? 'Choose another' : 'Choose a file'
                        }}
                    </Button>

                    <span
                        v-if="importForm.file"
                        class="min-w-0 truncate text-xs text-faint"
                        data-test="chosen-import-file"
                    >
                        {{ importForm.file.name }}
                    </span>
                </div>

                <InputError :message="importForm.errors.file" />
            </div>

            <DialogFooter>
                <Button variant="outline" @click="importing = false">
                    Cancel
                </Button>
                <Button
                    :disabled="!importForm.file || importForm.processing"
                    data-test="confirm-import-customers"
                    @click="sendImport"
                >
                    {{ importForm.processing ? 'Importing...' : 'Import' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
