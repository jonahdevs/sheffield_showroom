<script setup lang="ts">
import {
    ChevronDown,
    Download,
    FileSpreadsheet,
    FileText,
    FileType,
} from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';

const props = defineProps<{
    /** Where the list's export lives, without a query string. */
    url: string;

    /** The filters the list is currently under, straight off `useFilters`. */
    query?: Record<string, string>;

    name: string;

    /**
     * What this host can actually produce, as the server reported it. PDF needs
     * a headless browser the machine may not have, so an omitted format is a
     * download that would always fail.
     */
    formats?: string[];

    disabled?: boolean;
}>();

function offers(format: string): boolean {
    return props.formats === undefined || props.formats.includes(format);
}

/*
  A plain navigation, never an Inertia visit: the response is a file, and
  Inertia has nowhere to put one.
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
                variant="outline"
                :disabled="props.disabled"
                :data-test="`export-${props.name}`"
            >
                <Download />
                Export
                <Separator
                    orientation="vertical"
                    class="h-4! self-center bg-border"
                />
                <ChevronDown class="text-muted-foreground" />
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
