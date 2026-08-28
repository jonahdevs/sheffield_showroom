<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed } from 'vue';
import PageSizeSelect from '@/components/PageSizeSelect.vue';
import TablePagination from '@/components/TablePagination.vue';
import type { Paginator } from '@/components/TablePagination.vue';
import TableSkeleton from '@/components/TableSkeleton.vue';
import { Badge } from '@/components/ui/badge';
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
import { useFilters } from '@/composables/useFilters';
import { dashboard } from '@/routes';
import { index } from '@/routes/admin/permissions';

interface Paginated<T> extends Paginator {
    data: T[];
}

interface Option {
    value: string;
    label: string;
}

const props = defineProps<{
    permissions: Paginated<App.Data.PermissionRowData>;
    groups: Option[];
    page_sizes: number[];
    filters: { search: string; group: string };
}>();

const { filters, hasFilters, processing, clear } = useFilters({
    url: index.url(),
    initial: {
        search: props.filters.search ?? '',
        group: props.filters.group === '' ? 'all' : props.filters.group,
    },
    blank: { search: '', group: 'all' },
    only: ['permissions', 'filters'],
});

/** What Clear offers to undo: every filter set, wherever it was set. */
const activeFilterCount = computed(
    () => (filters.search === '' ? 0 : 1) + (filters.group === 'all' ? 0 : 1),
);

/** Three badges, then a count. A permission every role holds is not a list. */
const SHOWN = 3;

function headline(value: string): string {
    return value.replace(/[-_]/g, ' ');
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Permissions' },
        ],
    },
});
</script>

<!--
  Every capability the application checks for. The list is written in code and
  synced by `permissions:sync`, so nothing here is editable - a permission with
  no check behind it would be a promise the application never keeps. Which
  roles hand one out is changed on the Roles screen.
-->
<template>
    <Head title="Permissions" />

    <div class="flex flex-col gap-5">
        <div class="flex flex-col gap-2">
            <h1 class="text-2xl leading-tight">Permissions</h1>
            <p class="text-sm text-muted-foreground">
                Capabilities defined in code and handed out through roles.
            </p>
        </div>

        <Card class="min-w-0 gap-0 overflow-hidden p-0">
            <div
                class="flex flex-wrap items-center gap-2.5 border-b border-border px-5 py-3.5"
            >
                <InputGroup class="w-full max-w-72">
                    <InputGroupInput
                        v-model="filters.search"
                        type="search"
                        placeholder="Search permissions..."
                        aria-label="Search permissions"
                        data-test="permission-search"
                    />

                    <InputGroupAddon>
                        <Search />
                    </InputGroupAddon>
                </InputGroup>

                <div class="flex flex-wrap items-center gap-2.5 sm:ml-auto">
                    <Select v-model="filters.group">
                        <SelectTrigger
                            class="w-40"
                            aria-label="Filter by group"
                            data-test="permission-group"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All groups</SelectItem>
                            <SelectItem
                                v-for="group in props.groups"
                                :key="group.value"
                                :value="group.value"
                            >
                                {{ group.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Button
                        v-if="hasFilters"
                        variant="link"
                        size="sm"
                        class="px-1"
                        data-test="permission-clear"
                        @click="clear"
                    >
                        Clear
                        <span class="text-faint">
                            ({{ activeFilterCount }})
                        </span>
                    </Button>
                </div>
            </div>

            <!-- Ahead of the empty branch on purpose: mid-reload nobody knows
                 what is coming back, and flashing "no permission is defined"
                 over rows that are about to land reads as broken. -->
            <TableSkeleton
                v-if="processing"
                class="px-5 py-2"
                :rows="Math.min(props.permissions.per_page, 10)"
                :columns="3"
            />

            <p
                v-else-if="props.permissions.data.length === 0"
                class="m-5 rounded-lg bg-muted/50 p-4 text-sm text-muted-foreground"
                data-test="permissions-empty"
            >
                {{
                    hasFilters
                        ? 'No permission matches that search.'
                        : 'No permission is defined.'
                }}
            </p>

            <template v-else>
                <div class="overflow-x-auto">
                    <div
                        class="grid min-w-[760px] grid-cols-[minmax(0,1fr)_170px_minmax(0,1fr)] items-center gap-4 border-b border-border bg-muted/50 px-5 py-3.5 text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        <span>Permission</span>
                        <span>Group</span>
                        <span>Assigned to</span>
                    </div>

                    <div class="divide-y divide-border">
                        <div
                            v-for="permission in props.permissions.data"
                            :key="permission.value"
                            class="grid min-w-[760px] grid-cols-[minmax(0,1fr)_170px_minmax(0,1fr)] items-center gap-4 px-5 py-4"
                            :data-test="`permission-${permission.value}`"
                        >
                            <span class="min-w-0">
                                <span class="block text-xs font-bold">
                                    {{ permission.label }}
                                </span>
                                <span
                                    class="mt-1 block truncate font-mono text-xs text-faint"
                                >
                                    {{ permission.value }}
                                </span>
                            </span>

                            <span>
                                <Badge variant="outline">
                                    {{ permission.group_label }}
                                </Badge>
                            </span>

                            <span class="flex flex-wrap items-center gap-1.5">
                                <Badge
                                    v-for="role in permission.roles.slice(
                                        0,
                                        SHOWN,
                                    )"
                                    :key="role"
                                    class="capitalize"
                                >
                                    {{ headline(role) }}
                                </Badge>
                                <Badge v-if="permission.roles.length > SHOWN">
                                    +{{ permission.roles.length - SHOWN }}
                                </Badge>
                                <span
                                    v-if="permission.roles.length === 0"
                                    class="text-xs text-muted-foreground"
                                >
                                    Held by no role
                                </span>
                            </span>
                        </div>
                    </div>
                </div>

                <TablePagination
                    class="border-t border-border px-5 py-4"
                    :meta="props.permissions"
                    label="permissions"
                >
                    <PageSizeSelect
                        :sizes="props.page_sizes"
                        :current="props.permissions.per_page"
                    />
                </TablePagination>
            </template>
        </Card>
    </div>
</template>
