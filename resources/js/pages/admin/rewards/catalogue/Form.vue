<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import OptionCombobox from '@/components/OptionCombobox.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { confirmDelete } from '@/lib/confirm';
import { dashboard } from '@/routes';
import {
    destroy,
    index,
    store,
    update,
} from '@/routes/admin/rewards/catalogue';

const props = defineProps<{
    reward: App.Data.RewardData | null;
    types: { value: string; label: string }[];
    units: { value: string; label: string }[];
    products: App.Data.OptionData[];
    can: { update: boolean; delete: boolean };
}>();

const heading = computed(() => props.reward?.name ?? 'New reward');

/**
 * Reading needs only `rewards.view`, so this screen doubles as the viewer for
 * somebody who may not write: every field is disabled rather than hidden.
 */
const readOnly = computed(() => !props.can.update);

type RewardForm = {
    name: string;
    description: string;
    type: string;
    product_id: number | null;
    value: string;
    value_unit: string;
    terms: string;
    default_validity_days: string;
    is_active: boolean;
};

const form = useForm<RewardForm>({
    name: props.reward?.name ?? '',
    description: props.reward?.description ?? '',
    type: props.reward?.type ?? props.types[0]?.value ?? '',
    product_id: props.reward?.product_id ?? null,
    value: props.reward?.value ?? '',
    value_unit: props.reward?.value_unit ?? '',
    terms: props.reward?.terms ?? '',
    default_validity_days:
        props.reward?.default_validity_days === null ||
        props.reward?.default_validity_days === undefined
            ? ''
            : String(props.reward.default_validity_days),
    is_active: props.reward?.is_active ?? true,
});

/** `RewardRequest` requires `product_id` for this kind and prohibits it for every other. */
const handsOverProduct = computed(() => form.type === 'product');

/*
  A reward moved off Product must forget the product it named: left behind, the
  hidden picker would still hold a selection and the request would refuse a
  field the person can no longer see.
*/
watch(handsOverProduct, (isProduct) => {
    if (!isProduct) {
        form.product_id = null;
    }
});

async function removeReward() {
    const reward = props.reward;

    if (reward === null || !(await confirmDelete())) {
        return;
    }

    router.delete(destroy(reward.id).url);
}

function blankToNull(value: string): string | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed;
}

/** A cleared number box is nothing rather than zero. */
function numberOrNull(value: string): number | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : Number(trimmed);
}

/**
 * `product_id` is deliberately absent from the payload for every kind but
 * Product rather than sent as null: the rule refusing it is `prohibited`, which
 * wants a key that is simply not there. The server clears the column regardless.
 */
function submit() {
    form.transform((data) => {
        const reward = {
            name: data.name,
            description: blankToNull(data.description),
            type: data.type,
            /* The figure and its unit travel together or not at all - `10` is not
               a worth, and `RewardRequest` refuses either without the other. */
            value: blankToNull(data.value),
            value_unit: blankToNull(data.value_unit),
            terms: blankToNull(data.terms),
            default_validity_days: numberOrNull(data.default_validity_days),
            is_active: data.is_active,
        };

        return handsOverProduct.value
            ? { ...reward, product_id: data.product_id }
            : reward;
    });

    if (props.reward === null) {
        form.post(store().url);

        return;
    }

    form.patch(update(props.reward.id).url);
}

defineOptions({
    layout: (page: { reward: App.Data.RewardData | null }) => ({
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Catalogue', href: index().url },
            { title: page.reward === null ? 'Create' : 'Edit' },
        ],
    }),
});
</script>

<template>
    <Head :title="heading" />

    <div class="flex flex-col gap-5">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl leading-tight">{{ heading }}</h1>
                <Badge
                    v-if="props.reward && !props.reward.is_active"
                    variant="secondary"
                    data-test="reward-retired"
                >
                    Retired
                </Badge>
                <Badge
                    v-if="props.reward && props.reward.campaigns_count > 0"
                    variant="outline"
                    data-test="reward-held"
                >
                    Held by
                    {{ props.reward.campaigns_count }}
                    {{
                        props.reward.campaigns_count === 1
                            ? 'campaign'
                            : 'campaigns'
                    }}
                </Badge>
            </div>

            <p class="mt-2 text-sm text-muted-foreground">
                {{
                    props.reward === null
                        ? 'Describe it once. Every campaign that offers it from now on reads it from here.'
                        : 'Changing this changes what new campaigns will offer. Deadlines already promised are stamped on the win and stay where they are.'
                }}
            </p>
        </div>

        <form class="flex flex-col gap-5" @submit.prevent="submit">
            <Card as="section" class="gap-0 p-0">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        The reward
                    </h2>
                </div>

                <div class="p-5">
                    <div
                        class="flex flex-col gap-4 @2xl/page:grid @2xl/page:grid-cols-2 @2xl/page:gap-x-5.5 @2xl/page:gap-y-4.5"
                    >
                        <div class="@2xl/page:col-span-2">
                            <Label for="name">
                                Name <span class="text-primary">*</span>
                            </Label>
                            <Input
                                id="name"
                                v-model="form.name"
                                class="mt-2.25"
                                :disabled="readOnly"
                                placeholder="e.g. Free kitchen audit"
                                data-test="field-name"
                            />
                            <InputError :message="form.errors.name" />
                            <p class="mt-1.5 text-xs text-muted-foreground">
                                What a customer sees on the card they win.
                            </p>
                        </div>

                        <div class="@2xl/page:col-span-2">
                            <Label for="description">Description</Label>
                            <Textarea
                                id="description"
                                v-model="form.description"
                                class="mt-2.25"
                                :disabled="readOnly"
                                rows="2"
                                placeholder="What it is, in a line the floor can repeat."
                                data-test="field-description"
                            />
                            <InputError :message="form.errors.description" />
                        </div>

                        <div>
                            <Label for="type">
                                Kind <span class="text-primary">*</span>
                            </Label>
                            <NativeSelect
                                id="type"
                                v-model="form.type"
                                class="mt-2.25 w-full"
                                :disabled="readOnly"
                                data-test="field-type"
                            >
                                <NativeSelectOption
                                    v-for="type in props.types"
                                    :key="type.value"
                                    :value="type.value"
                                >
                                    {{ type.label }}
                                </NativeSelectOption>
                            </NativeSelect>
                            <InputError :message="form.errors.type" />
                        </div>

                        <div v-if="handsOverProduct">
                            <Label for="product_id">
                                Hands over <span class="text-primary">*</span>
                            </Label>
                            <div class="mt-2.25">
                                <OptionCombobox
                                    id="product_id"
                                    v-model="form.product_id"
                                    :options="props.products"
                                    placeholder="Choose the product"
                                    search-placeholder="Search by name or SKU"
                                    empty-text="No product matches that."
                                    data-test="field-product"
                                />
                            </div>
                            <InputError :message="form.errors.product_id" />
                        </div>

                        <div>
                            <Label for="value">Worth</Label>
                            <Input
                                id="value"
                                v-model="form.value"
                                type="number"
                                min="0"
                                step="0.01"
                                class="mt-2.25"
                                :disabled="readOnly"
                                placeholder="e.g. 10"
                                data-test="field-value"
                            />
                            <InputError :message="form.errors.value" />
                            <p class="mt-1.5 text-xs text-muted-foreground">
                                Most rewards carry no figure - a service is
                                worth what its terms say.
                            </p>
                        </div>

                        <div>
                            <Label for="value_unit">Read as</Label>
                            <NativeSelect
                                id="value_unit"
                                v-model="form.value_unit"
                                class="mt-2.25 w-full"
                                :disabled="readOnly"
                                data-test="field-value-unit"
                            >
                                <NativeSelectOption value="">
                                    No figure
                                </NativeSelectOption>
                                <NativeSelectOption
                                    v-for="unit in props.units"
                                    :key="unit.value"
                                    :value="unit.value"
                                >
                                    {{ unit.label }}
                                </NativeSelectOption>
                            </NativeSelect>
                            <InputError :message="form.errors.value_unit" />
                        </div>

                        <div>
                            <Label for="default_validity_days">
                                Valid for
                            </Label>
                            <Input
                                id="default_validity_days"
                                v-model="form.default_validity_days"
                                type="number"
                                min="1"
                                max="3650"
                                class="mt-2.25"
                                :disabled="readOnly"
                                placeholder="Days"
                                data-test="field-validity-days"
                            />
                            <InputError
                                :message="form.errors.default_validity_days"
                            />
                            <p class="mt-1.5 text-xs text-muted-foreground">
                                What a campaign attaching this starts from. Each
                                campaign can set its own.
                            </p>
                        </div>

                        <div class="@2xl/page:col-span-2">
                            <Label for="terms">Terms</Label>
                            <Textarea
                                id="terms"
                                v-model="form.terms"
                                class="mt-2.25"
                                :disabled="readOnly"
                                rows="3"
                                placeholder="The conditions the desk reads out when it is handed over."
                                data-test="field-terms"
                            />
                            <InputError :message="form.errors.terms" />
                        </div>

                        <div class="@2xl/page:col-span-2">
                            <Label
                                for="is_active"
                                class="flex items-center gap-2.5"
                            >
                                <Checkbox
                                    id="is_active"
                                    :model-value="form.is_active"
                                    :disabled="readOnly"
                                    data-test="field-is-active"
                                    @update:model-value="
                                        form.is_active = $event === true
                                    "
                                />
                                <span>Still offered</span>
                            </Label>
                            <InputError :message="form.errors.is_active" />
                            <p class="mt-1.5 text-xs text-muted-foreground">
                                Clear this to retire it. Campaigns already
                                handing it out are left exactly as they are.
                            </p>
                        </div>
                    </div>
                </div>
            </Card>

            <div
                v-if="!readOnly"
                class="flex flex-wrap items-center justify-end gap-3"
            >
                <!-- A reward a campaign holds cannot be deleted at all:
                     `campaign_rewards.reward_id` is `restrictOnDelete`. Retire it instead. -->
                <Button
                    v-if="props.can.delete"
                    type="button"
                    variant="ghost"
                    class="mr-auto text-destructive hover:text-destructive"
                    data-test="delete-reward"
                    @click="removeReward"
                >
                    <Trash2 />
                    Delete
                </Button>

                <Button as-child variant="outline">
                    <Link :href="index().url">Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    :disabled="form.processing || form.name.trim() === ''"
                    data-test="save-reward"
                >
                    {{ props.reward ? 'Save changes' : 'Add reward' }}
                </Button>
            </div>

            <div v-else class="flex items-center justify-end">
                <Button as-child variant="outline">
                    <Link :href="index().url">Close</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
