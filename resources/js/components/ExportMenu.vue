<script setup lang="ts">
import { Download, FileSpreadsheet, FileText, FileType } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

const props = defineProps<{
    /** Where the list's export lives, without a query string. */
    url: string;

    /**
     * The filters the list is currently under, straight off `useFilters`. They
     * go on the download's query string so the file is the list the viewer was
     * looking at rather than the whole table.
     */
    query?: Record<string, string>;

    /** What is being exported, for the labels and the `data-test` hooks. */
    name: string;

    /**
     * The formats this host can actually produce, as the server reported them.
     * Paper needs a headless browser a machine may not have, and offering a
     * download that always fails is worse than offering one format fewer.
     */
    formats?: string[];

    disabled?: boolean;
}>();

function offers(format: string): boolean {
    return props.formats === undefined || props.formats.includes(format);
}

/*
  A plain navigation rather than an Inertia visit: the response is a file, and
  Inertia has nowhere to put one. The browser follows the link, the download
  starts, and the page underneath never moves.
*/
function download(format: 'csv' | 'xlsx' | 'pdf') {
    const params = new URLSearchParams(props.query ?? {});
    params.set('format', format);

    window.location.href = `${props.url}?${params.toString()}`;
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="quiet"
                :disabled="props.disabled"
                :data-test="`export-${props.name}`"
            >
                <Download />
                Export
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-44">
            <DropdownMenuItem
                v-if="offers('csv')"
                class="cursor-pointer"
                :data-test="`export-${props.name}-csv`"
                @select="download('csv')"
            >
                <FileText />
                Export as CSV
            </DropdownMenuItem>

            <DropdownMenuItem
                v-if="offers('xlsx')"
                class="cursor-pointer"
                :data-test="`export-${props.name}-xlsx`"
                @select="download('xlsx')"
            >
                <FileSpreadsheet />
                Export as Excel
            </DropdownMenuItem>

            <DropdownMenuItem
                v-if="offers('pdf')"
                class="cursor-pointer"
                :data-test="`export-${props.name}-pdf`"
                @select="download('pdf')"
            >
                <FileType />
                Export as PDF
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
