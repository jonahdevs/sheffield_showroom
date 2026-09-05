<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Building2,
    CalendarClock,
    NotebookPen,
    Package,
    Pencil,
    Plus,
    Search,
    Trash2,
    UserRound,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import DateRangePicker from '@/components/DateRangePicker.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import PageSizeSelect from '@/components/PageSizeSelect.vue';
import StatTile from '@/components/StatTile.vue';
import TablePagination from '@/components/TablePagination.vue';
import type { Paginator } from '@/components/TablePagination.vue';
import TableSkeleton from '@/components/TableSkeleton.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
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
import { confirmDelete } from '@/lib/confirm';
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
    filters: {
        search: string;
        purpose: string;
        department: string;
        visitor_type: string;
        /** The name of the window, where one was named rather than drawn. */
        range: string;
        from: string;
        to: string;
    };
    date_label: string;
    presets: { value: string; label: string }[];
    /** Null where the window has no length, which turns the tiles' comparison off. */
    window_days: number | null;
    purposes: { value: string; label: string }[];
    departments: { value: string; label: string }[];
    visitor_types: { value: string; label: string; hint: string }[];
    page_sizes: number[];
    formats: string[];
    /** The window's figures, unaffected by the search and the two menu filters. */
    stats: App.Data.DashboardStatData[];
    scoped_to_own: boolean;
    can: {
        create: boolean;
        update: boolean;
        delete: boolean;
        export: boolean;
    };
}>();

/*
  The window rides in the same bag as the search and the purpose so it lands in
  the query string, survives a reload, counts towards `hasFilters`, is swept up
  by Clear and is carried into the download by `query`.

  `range` and the `from`/`to` pair are mutually exclusive - a named window never
  carries dates as well, so a bookmarked "this month" still opens this month next
  month rather than the days it happened to mean when it was saved.

  `stats`, `window_days` and `date_label` are in `only` because all three are the
  server's reading of the window and must reload with the rows.
*/
const { filters, query, hasFilters, processing, apply, clear } = useFilters({
    url: index.url(),
    initial: {
        search: props.filters.search ?? '',
        purpose: props.filters.purpose === '' ? 'all' : props.filters.purpose,
        department:
            props.filters.department === '' ? 'all' : props.filters.department,
        visitor_type:
            props.filters.visitor_type === ''
                ? 'all'
                : props.filters.visitor_type,
        range: props.filters.range ?? '',
        from: props.filters.range === '' ? (props.filters.from ?? '') : '',
        to: props.filters.range === '' ? (props.filters.to ?? '') : '',
    },
    blank: {
        search: '',
        purpose: 'all',
        department: 'all',
        visitor_type: 'all',
        range: '',
        from: '',
        to: '',
    },
    only: ['visits', 'filters', 'date_label', 'window_days', 'stats'],
});

/**
 * Applied at once rather than debounced, and each clears the other spelling of
 * the window: a URL carrying `range=this_month&from=2026-02-01` is one somebody
 * will eventually read as a contradiction and try to honour.
 */
function chooseWindow(from: string, to: string): void {
    apply({ range: '', from, to });
}

function choosePreset(range: string): void {
    apply({ range, from: '', to: '' });
}

/** A named window reads by its name; a drawn one by the server's resolved label. */
const windowLabel = computed(
    () =>
        props.presets.find((preset) => preset.value === props.filters.range)
            ?.label ?? props.date_label,
);

/** Blank where the window has no length - the tiles then say so themselves. */
const comparison = computed(() => {
    if (props.window_days === null) {
        return '';
    }

    return props.window_days === 1
        ? 'vs the day before'
        : `vs previous ${props.window_days} days`;
});

const TILES: Record<string, { icon: Component; colour: string }> = {
    visits: { icon: Users, colour: CHART_PALETTE[0] },
    customers: { icon: UserRound, colour: CHART_PALETTE[1] },
    follow_ups: { icon: CalendarClock, colour: CHART_PALETTE[2] },
};

function tile(key: string) {
    return TILES[key] ?? { icon: Users, colour: CHART_PALETTE[8] };
}

async function removeVisit(visit: App.Data.VisitRowData) {
    if (!(await confirmDelete())) {
        return;
    }

    router.delete(destroy(visit.id).url, { preserveScroll: true });
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
                <DateRangePicker
                    :from="props.filters.from"
                    :to="props.filters.to"
                    :label="windowLabel"
                    :presets="props.presets"
                    :active="props.filters.range"
                    data-test="visit-date-range"
                    @update="chooseWindow"
                    @preset="choosePreset"
                />

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

        <div
            class="grid gap-4 transition-opacity @2xl/page:grid-cols-3"
            :class="processing ? 'opacity-60' : ''"
            :aria-busy="processing"
        >
            <StatTile
                v-for="stat in props.stats"
                :key="stat.key"
                :stat="stat"
                :icon="tile(stat.key).icon"
                :colour="tile(stat.key).colour"
                :comparison="comparison"
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

                <Select v-model="filters.department">
                    <SelectTrigger
                        class="w-52"
                        aria-label="Filter by department"
                        data-test="visit-department-filter"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent align="end">
                        <SelectItem value="all">All departments</SelectItem>
                        <SelectItem
                            v-for="department in props.departments"
                            :key="department.value"
                            :value="department.value"
                        >
                            {{ department.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <Select v-model="filters.visitor_type">
                    <SelectTrigger
                        class="w-52"
                        aria-label="Filter by visitor type"
                        data-test="visit-visitor-type-filter"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent align="end">
                        <SelectItem value="all">All visitor types</SelectItem>
                        <SelectItem
                            v-for="visitor_type in props.visitor_types"
                            :key="visitor_type.value"
                            :value="visitor_type.value"
                        >
                            {{ visitor_type.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <!-- Ahead of the empty branch on purpose: mid-reload this must not flash "no visits". -->
            <TableSkeleton
                v-if="processing"
                class="px-5 py-2"
                :rows="Math.min(props.visits.per_page, 10)"
                :columns="8"
            />

            <div
                v-else-if="props.visits.data.length === 0"
                class="m-5 rounded-lg bg-muted/60 p-6 text-center"
                data-test="visits-empty"
            >
                <p class="text-sm text-muted-foreground">
                    {{
                        hasFilters
                            ? 'No visit matches those filters.'
                            : 'No visits have been logged yet.'
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
                        class="grid min-w-[1260px] grid-cols-[minmax(0,1.2fr)_minmax(0,0.85fr)_minmax(0,0.75fr)_minmax(0,0.7fr)_minmax(0,1.4fr)_minmax(0,0.85fr)_minmax(0,0.75fr)_84px] items-center gap-4 border-b border-border bg-muted/50 px-5 py-3.5 text-xs font-semibold"
                    >
                        <span>Customer</span>
                        <span>Company</span>
                        <span>Nature of visit</span>
                        <span>Department</span>
                        <span>Interested products</span>
                        <span>Respondent</span>
                        <span>Visit date &amp; time</span>
                        <span class="sr-only">Actions</span>
                    </div>

                    <div class="divide-y divide-divider">
                        <div
                            v-for="visit in props.visits.data"
                            :key="visit.id"
                            class="grid min-w-[1260px] grid-cols-[minmax(0,1.2fr)_minmax(0,0.85fr)_minmax(0,0.75fr)_minmax(0,0.7fr)_minmax(0,1.4fr)_minmax(0,0.85fr)_minmax(0,0.75fr)_84px] items-center gap-4 px-5 py-3.5"
                            :data-test="`visit-${visit.id}`"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-faint"
                                    aria-hidden="true"
                                >
                                    <!-- A company icon only for a customer
                                         buying for one: nobody asked a courier
                                         which they were. -->
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

                                    <!-- Only when they were not a customer.
                                         Most rows are, and a badge on every one
                                         of them says nothing. -->
                                    <span
                                        v-if="visit.visitor_type !== 'customer'"
                                        class="mt-0.5 inline-block rounded border px-1.5 py-0.5 text-[0.6875rem] text-faint"
                                        :data-test="`visitor-badge-${visit.id}`"
                                    >
                                        {{ visit.visitor_type_label }}
                                    </span>
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

                            <span
                                class="truncate text-xs"
                                :class="
                                    visit.department_label ? '' : 'text-faint'
                                "
                                :title="visit.department_label ?? undefined"
                            >
                                {{ visit.department_label ?? '--' }}
                            </span>

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
                                    {{ visit.visited_time }}
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
                                    @click="removeVisit(visit)"
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
</template>
