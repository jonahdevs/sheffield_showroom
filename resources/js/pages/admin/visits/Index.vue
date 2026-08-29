<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Building2,
    CalendarDays,
    CalendarRange,
    ClipboardList,
    NotebookPen,
    Package,
    Pencil,
    Plus,
    Search,
    Sun,
    Trash2,
    UserRound,
} from '@lucide/vue';
import type { Component } from 'vue';
import { ref } from 'vue';
import ExportMenu from '@/components/ExportMenu.vue';
import PageSizeSelect from '@/components/PageSizeSelect.vue';
import StatTile from '@/components/StatTile.vue';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { CHART_PALETTE } from '@/composables/useChartTheme';
import { useFilters } from '@/composables/useFilters';
import { dashboard } from '@/routes';
import {
    create,
    destroy,
    edit,
    exportMethod,
    index,
} from '@/routes/admin/visits';

interface Paginated<T> extends Paginator {
    data: T[];
}

const props = defineProps<{
    visits: Paginated<App.Data.VisitRowData>;
    filters: { search: string; purpose: string };
    purposes: { value: string; label: string }[];
    page_sizes: number[];
    formats: string[];
    total: number;
    /* How busy the floor has been, unaffected by the search and the purpose
       filter - see the controller's `stats()` for why. */
    stats: App.Data.DashboardStatData[];
    /** True for a salesperson, who sees only what they logged. */
    scoped_to_own: boolean;
    can: {
        create: boolean;
        update: boolean;
        delete: boolean;
        export: boolean;
    };
}>();

const { filters, query, hasFilters, processing, clear } = useFilters({
    url: index.url(),
    initial: {
        search: props.filters.search ?? '',
        purpose: props.filters.purpose === '' ? 'all' : props.filters.purpose,
    },
    blank: { search: '', purpose: 'all' },
    only: ['visits', 'filters'],
});

/**
 * What the page hangs on each figure: a glyph, a colour, and what its delta is
 * measured against.
 *
 * Kept here rather than sent from the server because none of it is data - it
 * is how this screen chooses to present four integers, and a controller has no
 * business holding an icon. The palette is the dashboard's, so a reader moving
 * between the two rows reads them as the same instrument.
 */
const TILES: Record<
    string,
    { icon: Component; colour: string; comparison: string }
> = {
    total: {
        icon: ClipboardList,
        colour: CHART_PALETTE[0],
        comparison: '',
    },
    today: {
        icon: Sun,
        colour: CHART_PALETTE[1],
        comparison: 'vs yesterday',
    },
    week: {
        icon: CalendarDays,
        colour: CHART_PALETTE[2],
        comparison: 'vs last week',
    },
    month: {
        icon: CalendarRange,
        colour: CHART_PALETTE[3],
        comparison: 'vs last month',
    },
};

function tile(key: string) {
    return (
        TILES[key] ?? {
            icon: ClipboardList,
            colour: CHART_PALETTE[8],
            comparison: '',
        }
    );
}

const deleting = ref<App.Data.VisitRowData | null>(null);

function confirmDelete() {
    const visit = deleting.value;

    if (visit === null) {
        return;
    }

    router.delete(destroy(visit.id).url, {
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
            { title: 'Visits' },
        ],
    },
});
</script>

<!--
  Every call at the showroom, newest first. A table rather than tiles: a visit
  is read across - who, why, when, how long - and there is no picture to
  recognise it by.
-->
<template>
    <Head title="Visits" />

    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-start gap-4">
            <div class="flex min-w-0 flex-1 flex-col gap-2">
                <h1 class="text-2xl leading-tight">Visits</h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        props.scoped_to_own
                            ? 'The visits you have logged.'
                            : 'Every call at the showroom.'
                    }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <!-- The export follows the filters and the same visibility
                     split the list sits behind: a salesperson downloads their
                     own visits, not the floor's. -->
                <ExportMenu
                    v-if="props.can.export"
                    :url="exportMethod().url"
                    :query="query"
                    :formats="props.formats"
                    name="visits"
                />

                <Button v-if="props.can.create" as-child data-test="new-visit">
                    <Link :href="create().url">
                        <Plus />
                        New visit
                    </Link>
                </Button>
            </div>
        </div>

        <!-- Read before the list rather than after it: somebody opening
             this screen wants to know whether the floor has been busy before
             they start reading rows, and the four windows narrow in the order
             a person asks them. -->
        <div class="grid gap-4 @lg/page:grid-cols-2 @3xl/page:grid-cols-4">
            <StatTile
                v-for="stat in props.stats"
                :key="stat.key"
                :stat="stat"
                :icon="tile(stat.key).icon"
                :colour="tile(stat.key).colour"
                :comparison="tile(stat.key).comparison"
            />
        </div>

        <Card class="min-w-0 gap-0 overflow-hidden p-0">
            <div
                class="flex flex-wrap items-center gap-2.5 border-b border-border px-5 py-3.5"
            >
                <InputGroup class="w-full max-w-80">
                    <InputGroupInput
                        v-model="filters.search"
                        type="search"
                        placeholder="Search customer or notes..."
                        aria-label="Search visits"
                        data-test="visit-search"
                    />

                    <InputGroupAddon>
                        <Search />
                    </InputGroupAddon>
                </InputGroup>

                <Button
                    v-if="hasFilters"
                    variant="link"
                    size="sm"
                    class="px-1"
                    data-test="visit-clear"
                    @click="clear"
                >
                    Clear
                </Button>

                <!-- `ml-auto` rather than a spacer, so the purpose sits at the
                     far right on a wide bar and simply wraps under the search
                     on a narrow one. -->
                <Select v-model="filters.purpose">
                    <SelectTrigger
                        class="w-52 sm:ml-auto"
                        aria-label="Filter by nature of visit"
                        data-test="visit-purpose-filter"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent align="end">
                        <SelectItem value="all">All visits</SelectItem>
                        <SelectItem
                            v-for="purpose in props.purposes"
                            :key="purpose.value"
                            :value="purpose.value"
                        >
                            {{ purpose.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <!-- Ahead of the empty branch on purpose: mid-reload nobody knows
                 what is coming back, and flashing "no visits" over rows about
                 to land reads as broken. -->
            <TableSkeleton
                v-if="processing"
                class="px-5 py-2"
                :rows="Math.min(props.visits.per_page, 10)"
                :columns="7"
            />

            <div
                v-else-if="props.visits.data.length === 0"
                class="m-5 rounded-lg bg-muted/60 p-6 text-center"
                data-test="visits-empty"
            >
                <p class="text-sm text-muted-foreground">
                    {{
                        hasFilters
                            ? 'No visit matches that search.'
                            : 'No visits have been logged yet.'
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

            <template v-else>
                <div class="overflow-x-auto">
                    <!-- Semibold rather than bold: the lead cell of every row below
                         is itself text-xs font-bold, so a bold header would read as
                         one more row instead of the label for all of them. -->
                    <div
                        class="grid min-w-[1120px] grid-cols-[minmax(0,1.2fr)_minmax(0,0.85fr)_minmax(0,0.75fr)_minmax(0,1.4fr)_minmax(0,0.85fr)_minmax(0,0.75fr)_84px] items-center gap-4 border-b border-border bg-muted/50 px-5 py-3.5 text-xs font-semibold"
                    >
                        <span>Customer</span>
                        <span>Company</span>
                        <span>Purpose</span>
                        <span>Interested products</span>
                        <span>Respondent</span>
                        <span>Visit date &amp; time</span>
                        <span class="sr-only">Actions</span>
                    </div>

                    <div class="divide-y divide-divider">
                        <div
                            v-for="visit in props.visits.data"
                            :key="visit.id"
                            class="grid min-w-[1120px] grid-cols-[minmax(0,1.2fr)_minmax(0,0.85fr)_minmax(0,0.75fr)_minmax(0,1.4fr)_minmax(0,0.85fr)_minmax(0,0.75fr)_84px] items-center gap-4 px-5 py-3.5"
                            :data-test="`visit-${visit.id}`"
                        >
                            <!-- Read the same way as the customers list: the
                                 kind as an icon, the person in bold, and what
                                 identifies them under it. -->
                            <div class="flex min-w-0 items-center gap-3">
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-faint"
                                    aria-hidden="true"
                                >
                                    <Building2
                                        v-if="visit.customer_type === 'company'"
                                        class="size-4"
                                    />
                                    <UserRound v-else class="size-4" />
                                </span>

                                <div class="min-w-0">
                                    <p class="truncate text-xs font-bold">
                                        {{ visit.customer_name }}
                                    </p>
                                    <p
                                        v-if="visit.customer_phone"
                                        class="mt-0.5 truncate text-xs text-faint tabular-nums"
                                    >
                                        {{ visit.customer_phone }}
                                    </p>
                                </div>
                            </div>

                            <span
                                class="truncate text-xs"
                                :class="
                                    visit.customer_company ? '' : 'text-faint'
                                "
                                :title="visit.customer_company ?? undefined"
                            >
                                {{ visit.customer_company ?? '--' }}
                            </span>

                            <div class="flex min-w-0 items-center gap-2">
                                <span class="truncate text-xs">
                                    {{ visit.purpose_label }}
                                </span>

                                <NotebookPen
                                    v-if="visit.has_notes"
                                    class="size-3.5 shrink-0 text-faint"
                                    aria-label="Has a write-up"
                                />
                            </div>

                            <!-- Named rather than counted: "3" tells nobody
                                 whether to follow up with a quote for mabati
                                 or for gutters. -->
                            <div class="flex min-w-0 items-center gap-1.5">
                                <Package
                                    v-if="visit.products.length > 0"
                                    class="size-3.5 shrink-0 text-faint"
                                    aria-hidden="true"
                                />
                                <span
                                    class="truncate text-xs"
                                    :class="
                                        visit.products.length > 0
                                            ? ''
                                            : 'text-faint'
                                    "
                                    :title="
                                        visit.products.length > 0
                                            ? visit.products.join(', ')
                                            : undefined
                                    "
                                >
                                    {{
                                        visit.products.length > 0
                                            ? visit.products.join(', ')
                                            : '--'
                                    }}
                                </span>
                            </div>

                            <span class="truncate text-xs text-faint">
                                {{ visit.attended_by ?? '--' }}
                            </span>

                            <div class="min-w-0 text-xs">
                                <p class="truncate tabular-nums">
                                    {{ visit.visited_on }}
                                </p>
                                <p class="mt-0.5 text-faint tabular-nums">
                                    {{ visit.visited_time
                                    }}{{
                                        visit.duration
                                            ? ` - ${visit.duration}`
                                            : ''
                                    }}
                                </p>
                            </div>

                            <div class="flex items-center justify-end gap-1">
                                <Button
                                    v-if="props.can.update"
                                    as-child
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="`Edit the visit by ${visit.customer_name}`"
                                    :data-test="`edit-${visit.id}`"
                                >
                                    <Link :href="edit(visit.id).url">
                                        <Pencil />
                                    </Link>
                                </Button>

                                <Button
                                    v-if="props.can.delete"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Remove the visit by ${visit.customer_name}`"
                                    :data-test="`delete-${visit.id}`"
                                    @click="deleting = visit"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <TablePagination
                    class="border-t border-border px-5 py-4"
                    :meta="props.visits"
                    label="visits"
                >
                    <PageSizeSelect
                        :sizes="props.page_sizes"
                        :current="props.visits.per_page"
                    />
                </TablePagination>
            </template>
        </Card>
    </div>

    <Dialog
        :open="deleting !== null"
        @update:open="!$event && (deleting = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Remove this visit?</DialogTitle>
                <DialogDescription>
                    It comes off the log and out of the counts behind it. The
                    customer and the products it names are untouched, and the
                    record can be brought back.
                </DialogDescription>
            </DialogHeader>

            <div
                class="flex flex-col gap-1.5 rounded-lg bg-muted/60 p-3.5 text-xs"
            >
                <span class="font-bold">{{ deleting?.customer_name }}</span>
                <span class="text-faint">
                    {{ deleting?.purpose_label }} -
                    {{ deleting?.visited_on }} at {{ deleting?.visited_time }}
                </span>
            </div>

            <DialogFooter>
                <Button variant="quiet" @click="deleting = null">Cancel</Button>
                <Button
                    variant="destructive"
                    data-test="confirm-delete-visit"
                    @click="confirmDelete"
                >
                    Remove visit
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
