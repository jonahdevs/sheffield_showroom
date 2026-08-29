<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ImageOff, Trash2, Upload } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import { index, store, update } from '@/routes/admin/products';

const props = defineProps<{
    product: App.Data.ProductData | null;
    statuses: { value: string; label: string }[];
}>();

const form = useForm<{
    name: string;
    sku: string;
    status: App.Enums.ProductStatus;
    image: File | null;
    remove_image: boolean;
}>({
    name: props.product?.name ?? '',
    sku: props.product?.sku ?? '',
    /* A new product is one somebody is putting on the floor, so it starts
       there. Anything else is a decision, and a decision belongs to whoever
       makes it rather than to a default. */
    status: props.product?.status ?? 'published',
    image: null,
    remove_image: false,
});

/**
 * What choosing this status will mean once it is saved. Worth spelling out for
 * one of them: `Inactive` is the only status the website cannot undo, and that
 * is exactly why somebody would reach for it.
 */
const statusHint = computed(() => {
    switch (form.status) {
        case 'published':
            return 'On the floor and shown to customers.';
        case 'draft':
            return 'Not shown yet. A sync sets this for anything unpublished on the website.';
        case 'inactive':
            return 'Kept off the floor by a decision made here. A sync will not undo it.';
        case 'archived':
            return 'No longer sold. Usually set by a sync when the website drops it.';
        default:
            return '';
    }
});

const fileInput = ref<HTMLInputElement | null>(null);

/** An object URL for the file just chosen, so the tile shows it before upload. */
const chosenPreview = ref<string | null>(null);

watch(
    () => form.image,
    (file, previous) => {
        if (chosenPreview.value !== null) {
            /* Revoked rather than left behind: an object URL holds the file in
               memory until the document goes away. */
            URL.revokeObjectURL(chosenPreview.value);
            chosenPreview.value = null;
        }

        if (file instanceof File) {
            chosenPreview.value = URL.createObjectURL(file);
        }

        void previous;
    },
);

const preview = computed(() => {
    if (chosenPreview.value !== null) {
        return chosenPreview.value;
    }

    return form.remove_image ? null : (props.product?.image_url ?? null);
});

const heading = computed(() => props.product?.name ?? 'New product');

function choose(event: Event) {
    const input = event.target as HTMLInputElement;

    form.image = input.files?.[0] ?? null;
    form.remove_image = false;
}

/**
 * Clearing the picture is two different things: dropping a file chosen a
 * moment ago, or asking the server to remove one already saved.
 */
function clearImage() {
    form.image = null;
    form.remove_image = props.product?.image_url != null;

    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

function submit() {
    /* A file cannot ride on a PATCH body, so both routes take a POST and the
       server tells them apart by the URL. */
    if (props.product) {
        form.post(update(props.product.id).url);

        return;
    }

    form.post(store().url);
}

defineOptions({
    layout: (page: { product: App.Data.ProductData | null }) => ({
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Products', href: index().url },
            { title: page.product ? 'Edit' : 'Create' },
        ],
    }),
});
</script>

<!--
  Four fields, because that is all the floor needs: the picture to recognise it
  by, the code to quote it by, the name, and whether it belongs out there at
  all. Price and specification live on the main website, and two places holding
  the same price is one place holding the wrong one.
-->
<template>
    <Head :title="heading" />

    <div class="flex flex-col gap-5">
        <div>
            <h1 class="text-2xl leading-tight">{{ heading }}</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                A name is all that is required. The picture and the SKU can
                follow.
            </p>
        </div>

        <div
            v-if="props.product?.is_synced"
            class="rounded-xl border border-border bg-muted/60 p-3.5 text-xs text-muted-foreground"
            data-test="synced-notice"
        >
            This product came from the main website. Anything changed here will
            be overwritten the next time the catalogue is synced - except the
            status, if you set it to Inactive. That one is yours and the sync
            leaves it alone.
        </div>

        <form class="flex flex-col gap-5" @submit.prevent="submit">
            <Card as="section" class="gap-0 p-0">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Basic information
                    </h2>
                </div>

                <div class="p-5">
                    <div
                        class="flex flex-col gap-4 @2xl/page:grid @2xl/page:grid-cols-2 @2xl/page:gap-x-5.5 @2xl/page:gap-y-4.5"
                    >
                        <div>
                            <Label for="name">
                                Name <span class="text-primary">*</span>
                            </Label>
                            <Input
                                id="name"
                                v-model="form.name"
                                class="mt-2.25"
                                placeholder="e.g. Galvanised Corrugated Sheet"
                                data-test="field-name"
                            />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div>
                            <Label for="sku">SKU</Label>
                            <Input
                                id="sku"
                                v-model="form.sku"
                                class="mt-2.25 font-mono"
                                placeholder="e.g. SS-3042-GC"
                                data-test="field-sku"
                            />
                            <InputError :message="form.errors.sku" />
                        </div>

                        <div>
                            <Label for="status">Status</Label>
                            <Select
                                :model-value="form.status"
                                @update:model-value="
                                    form.status =
                                        $event as App.Enums.ProductStatus
                                "
                            >
                                <SelectTrigger
                                    id="status"
                                    class="mt-2.25 w-full"
                                    data-test="field-status"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="status in props.statuses"
                                        :key="status.value"
                                        :value="status.value"
                                        :data-test="`status-${status.value}`"
                                    >
                                        {{ status.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p class="mt-1.5 text-xs text-faint">
                                {{ statusHint }}
                            </p>
                            <InputError :message="form.errors.status" />
                        </div>
                    </div>
                </div>
            </Card>

            <Card as="section" class="gap-0 p-0">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Image
                    </h2>
                </div>

                <div class="flex flex-wrap items-start gap-5 p-5">
                    <div
                        class="flex aspect-4/3 w-full max-w-56 items-center justify-center overflow-hidden rounded-lg border border-border bg-muted"
                    >
                        <img
                            v-if="preview"
                            :src="preview"
                            alt="Product image preview"
                            class="size-full object-contain"
                            data-test="image-preview"
                        />
                        <ImageOff
                            v-else
                            class="size-7 text-faint"
                            aria-hidden="true"
                        />
                    </div>

                    <div class="flex min-w-0 flex-1 flex-col gap-2.5">
                        <Label for="image">Product photo</Label>
                        <p class="text-xs text-faint">
                            JPG, PNG or WEBP, up to 4MB. One picture per
                            product.
                        </p>

                        <input
                            id="image"
                            ref="fileInput"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="sr-only"
                            data-test="field-image"
                            @change="choose"
                        />

                        <div class="mt-1 flex flex-wrap items-center gap-2.5">
                            <Button
                                type="button"
                                variant="quiet"
                                size="sm"
                                data-test="choose-image"
                                @click="fileInput?.click()"
                            >
                                <Upload />
                                {{ preview ? 'Replace' : 'Choose a picture' }}
                            </Button>

                            <Button
                                v-if="preview"
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="text-destructive hover:text-destructive"
                                data-test="clear-image"
                                @click="clearImage"
                            >
                                <Trash2 />
                                Remove
                            </Button>
                        </div>

                        <p
                            v-if="form.image"
                            class="truncate text-xs text-faint"
                            data-test="chosen-file"
                        >
                            {{ form.image.name }}
                        </p>

                        <InputError :message="form.errors.image" />
                    </div>
                </div>
            </Card>

            <div class="flex items-center justify-end gap-3">
                <Button as-child variant="quiet">
                    <Link :href="index().url">Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    :disabled="form.processing || form.name.trim() === ''"
                    data-test="save-product"
                >
                    {{ props.product ? 'Save changes' : 'Add product' }}
                </Button>
            </div>
        </form>
    </div>
</template>
