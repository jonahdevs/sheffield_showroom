<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, House } from '@lucide/vue';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type Props = {
    breadcrumbs: BreadcrumbItemType[];
};

defineProps<Props>();
</script>

<!--
  A house glyph on the first crumb, muted links, a bold leaf, and chevrons a
  shade lighter than the text so the trail reads as one line rather than as
  punctuation.

  It never wraps: on a narrow page the bar clips and the leaf truncates, which
  keeps a long trail from pushing the page heading down a row.
-->
<template>
    <Breadcrumb class="min-w-0">
        <BreadcrumbList
            class="flex-nowrap gap-2 text-xs whitespace-nowrap sm:gap-2"
        >
            <template v-for="(item, index) in breadcrumbs" :key="index">
                <BreadcrumbItem class="gap-1.5">
                    <BreadcrumbPage
                        v-if="index === breadcrumbs.length - 1"
                        class="flex items-center gap-1.5 truncate font-bold text-foreground"
                    >
                        <House
                            v-if="index === 0"
                            class="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        {{ item.title }}
                    </BreadcrumbPage>

                    <BreadcrumbLink v-else-if="item.href" as-child>
                        <Link
                            :href="item.href"
                            class="flex items-center gap-1.5 text-muted-foreground transition-colors hover:text-primary"
                        >
                            <House
                                v-if="index === 0"
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            {{ item.title }}
                        </Link>
                    </BreadcrumbLink>

                    <span
                        v-else
                        class="flex items-center gap-1.5 text-muted-foreground"
                    >
                        <House
                            v-if="index === 0"
                            class="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        {{ item.title }}
                    </span>
                </BreadcrumbItem>

                <BreadcrumbSeparator
                    v-if="index !== breadcrumbs.length - 1"
                    class="text-neutral-300 dark:text-neutral-700 [&>svg]:size-3.5"
                >
                    <ChevronRight :stroke-width="2" />
                </BreadcrumbSeparator>
            </template>
        </BreadcrumbList>
    </Breadcrumb>
</template>
