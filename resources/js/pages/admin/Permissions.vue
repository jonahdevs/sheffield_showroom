<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Pin, Search } from '@lucide/vue';
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

const activeFilterCount = computed(
    () => (filters.search === '' ? 0 : 1) + (filters.group === 'all' ? 0 : 1),
);

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
  Read-only by design: the list is written in code and synced by
  `permissions:sync`. The users named in "Assigned to" are why the screen exists
  - a permission granted straight to an account shows nowhere on the Roles
  screen, so this is the only place such a grant can be audited.
-->
<template>
    <Head title="Permissions" />

    <div class="flex flex-col gap-5">
        <div class="flex flex-col gap-2">
            <h1 class="text-2xl leading-tight">Permissions</h1>
            <p class="text-sm text-muted-foreground">
                Capabilities defined in code, handed out through roles or pinned
                to one account.
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

            <!-- Ahead of the empty branch on purpose: mid-reload this must not flash "no permission is defined". -->
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
                        class="grid min-w-[760px] grid-cols-[minmax(0,1fr)_170px_minmax(0,1fr)] items-center gap-4 border-b border-border bg-muted/50 px-5 py-3.5 text-xs font-semibold"
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
                                    v-if="
                                        permission.roles.length === 0 &&
                                        permission.users.length === 0
                                    "
                                    class="text-xs text-muted-foreground"
                                >
                                    Held by no role
                                </span>

                                <Badge
                                    v-for="user in permission.users.slice(
                                        0,
                                        SHOWN,
                                    )"
                                    :key="user"
                                    variant="outline"
                                    :data-test="`direct-${permission.value}`"
                                >
                                    <Pin class="size-3" />
                                    {{ user }}
                                </Badge>
                                <Badge
                                    v-if="permission.users.length > SHOWN"
                                    variant="outline"
                                >
                                    +{{ permission.users.length - SHOWN }}
                                </Badge>
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
