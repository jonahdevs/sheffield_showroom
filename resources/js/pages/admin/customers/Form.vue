<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import InputError from '@/components/InputError.vue';
import PhoneInput from '@/components/PhoneInput.vue';
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
import { Textarea } from '@/components/ui/textarea';
import { chosenOption, focusIf, openOnStored } from '@/lib/options';
import { dashboard } from '@/routes';
import { index, store, update } from '@/routes/admin/customers';

const props = defineProps<{
    customer: App.Data.CustomerFormData | null;
    types: { value: string; label: string }[];
    segments: { value: string; label: string }[];
    default_country: string;
}>();

type CustomerType = 'individual' | 'company';

const form = useForm({
    type: (props.customer?.type ?? 'individual') as CustomerType,
    name: props.customer?.name ?? '',
    phone: props.customer?.phone ?? '',
    email: props.customer?.email ?? '',
    id_number: props.customer?.id_number ?? '',
    company_name: props.customer?.company_name ?? '',
    segment: props.customer?.segment ?? '',
    country: props.customer?.country ?? props.default_country,
    state: props.customer?.state ?? '',
    city: props.customer?.city ?? '',
    street_address: props.customer?.street_address ?? '',
    area: props.customer?.area ?? '',
    postal_code: props.customer?.postal_code ?? '',
    notes: props.customer?.notes ?? '',
});

/* `segment` is free text and the menu is only a suggestion, so a stored value
   matching no option is one somebody typed: it opens on Other with the box
   filled rather than falling off the form and being overwritten on save. */
const { choice: segmentChoice, other: segmentOther } = openOnStored(
    props.segments,
    props.customer?.segment,
    '',
);

const segmentOtherBox = ref<HTMLElement | null>(null);

function focusSegmentOther(chosen: unknown) {
    focusIf(chosen === 'other', segmentOtherBox);
}

watchEffect(() => {
    form.segment = chosenOption(segmentChoice.value, segmentOther.value);
});

const isCompany = computed(() => form.type === 'company');

const heading = computed(() =>
    props.customer ? props.customer.display_name : 'New customer',
);

/**
 * Deliberately keeps every field: switching type only opens or closes the
 * business section, so a mis-click throws nothing typed away.
 */
function chooseType(type: CustomerType) {
    form.type = type;
    form.clearErrors();
}

const canSubmit = computed(() => {
    if (
        form.processing ||
        form.name.trim() === '' ||
        form.phone.trim() === ''
    ) {
        return false;
    }

    return !isCompany.value || form.company_name.trim() !== '';
});

function submit() {
    if (props.customer) {
        form.patch(update(props.customer.id).url);

        return;
    }

    form.post(store().url);
}

/** A callback rather than a static object: the same component serves both routes. */
defineOptions({
    layout: (page: { customer: App.Data.CustomerFormData | null }) => ({
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Customers', href: index().url },
            { title: page.customer ? 'Edit' : 'Create' },
        ],
    }),
});
</script>

<!-- Rows break on `@2xl/page` / `@4xl/page`, never viewport breakpoints:
     behind the rail those promise room the form does not have. -->
<template>
    <Head :title="heading" />

    <div class="flex flex-col gap-5">
        <div>
            <h1 class="text-2xl leading-tight">{{ heading }}</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                A name and a phone number are all that is needed. Everything
                else can be filled in as you learn it.
            </p>
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
                        class="flex flex-col gap-4 @2xl/page:grid @2xl/page:grid-cols-2 @2xl/page:gap-x-5.5 @2xl/page:gap-y-4.5 @4xl/page:grid-cols-3"
                    >
                        <div>
                            <Label for="type">
                                Customer type
                                <span class="text-primary">*</span>
                            </Label>
                            <Select
                                :model-value="form.type"
                                @update:model-value="
                                    (value) => chooseType(value as CustomerType)
                                "
                            >
                                <SelectTrigger
                                    id="type"
                                    class="mt-2.25 w-full"
                                    data-test="field-type"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="type in props.types"
                                        :key="type.value"
                                        :value="type.value"
                                        :data-test="`type-${type.value}`"
                                    >
                                        {{ type.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.type" />
                        </div>

                        <div>
                            <Label for="name">
                                Full name <span class="text-primary">*</span>
                            </Label>
                            <Input
                                id="name"
                                v-model="form.name"
                                class="mt-2.25"
                                placeholder="e.g. Achieng Odhiambo"
                                autocomplete="name"
                                data-test="field-name"
                            />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div>
                            <Label for="phone">
                                Phone number
                                <span class="text-primary">*</span>
                            </Label>
                            <div class="mt-2.25">
                                <PhoneInput
                                    id="phone"
                                    v-model="form.phone"
                                    data-test="field-phone"
                                />
                            </div>
                            <InputError :message="form.errors.phone" />
                        </div>

                        <div>
                            <Label for="email">Email</Label>
                            <Input
                                id="email"
                                v-model="form.email"
                                type="email"
                                class="mt-2.25"
                                placeholder="Optional"
                                autocomplete="email"
                                data-test="field-email"
                            />
                            <InputError :message="form.errors.email" />
                        </div>

                        <div>
                            <Label for="id_number">ID number</Label>
                            <Input
                                id="id_number"
                                v-model="form.id_number"
                                class="mt-2.25"
                                inputmode="numeric"
                                placeholder="Optional"
                                data-test="field-id-number"
                            />
                            <InputError :message="form.errors.id_number" />
                        </div>
                    </div>
                </div>
            </Card>

            <Card v-if="isCompany" as="section" class="gap-0 p-0">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Business
                    </h2>
                </div>

                <div class="p-5">
                    <div
                        class="flex flex-col gap-4 @2xl/page:grid @2xl/page:grid-cols-2 @2xl/page:gap-x-5.5 @2xl/page:gap-y-4.5"
                    >
                        <div>
                            <Label for="company_name">
                                Company name
                                <span class="text-primary">*</span>
                            </Label>
                            <Input
                                id="company_name"
                                v-model="form.company_name"
                                class="mt-2.25"
                                placeholder="e.g. Mwangi Builders Ltd"
                                autocomplete="organization"
                                data-test="field-company-name"
                            />
                            <InputError :message="form.errors.company_name" />
                        </div>

                        <div>
                            <Label for="segment">Segment</Label>
                            <Select
                                v-model="segmentChoice"
                                @update:model-value="focusSegmentOther"
                            >
                                <SelectTrigger
                                    id="segment"
                                    class="mt-2.25 w-full"
                                    data-test="field-segment"
                                >
                                    <SelectValue
                                        placeholder="Which trade are they in?"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="segment in props.segments"
                                        :key="segment.value"
                                        :value="segment.value"
                                    >
                                        {{ segment.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>

                            <!-- The menu is short by design; a trade it does
                                 not cover is typed here and stored as
                                 written. -->
                            <Input
                                v-if="segmentChoice === 'other'"
                                id="segment-other"
                                v-model="segmentOther"
                                class="mt-2.25"
                                maxlength="120"
                                placeholder="Name their trade"
                                aria-label="Name the segment they are in"
                                ref="segmentOtherBox"
                                data-test="field-segment-other"
                            />

                            <InputError :message="form.errors.segment" />
                        </div>
                    </div>
                </div>
            </Card>

            <Card as="section" class="gap-0 p-0">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Address
                    </h2>
                </div>

                <div class="p-5">
                    <div
                        class="flex flex-col gap-4 @2xl/page:grid @2xl/page:grid-cols-2 @2xl/page:gap-x-5.5 @2xl/page:gap-y-4.5 @4xl/page:grid-cols-3"
                    >
                        <div>
                            <Label for="country">
                                Country <span class="text-primary">*</span>
                            </Label>
                            <Input
                                id="country"
                                v-model="form.country"
                                class="mt-2.25"
                                autocomplete="country-name"
                                data-test="field-country"
                            />
                            <InputError :message="form.errors.country" />
                        </div>

                        <div>
                            <Label for="state">State / Province</Label>
                            <Input
                                id="state"
                                v-model="form.state"
                                class="mt-2.25"
                                placeholder="e.g. Nairobi County"
                                autocomplete="address-level1"
                                data-test="field-state"
                            />
                            <InputError :message="form.errors.state" />
                        </div>

                        <div>
                            <Label for="city">City</Label>
                            <Input
                                id="city"
                                v-model="form.city"
                                class="mt-2.25"
                                placeholder="e.g. Nairobi"
                                autocomplete="address-level2"
                                data-test="field-city"
                            />
                            <InputError :message="form.errors.city" />
                        </div>

                        <div>
                            <Label for="street_address">Street address</Label>
                            <Input
                                id="street_address"
                                v-model="form.street_address"
                                class="mt-2.25"
                                placeholder="e.g. Plot 14, Enterprise Road"
                                autocomplete="address-line1"
                                data-test="field-street-address"
                            />
                            <InputError :message="form.errors.street_address" />
                        </div>

                        <div>
                            <Label for="area">Area / Estate</Label>
                            <Input
                                id="area"
                                v-model="form.area"
                                class="mt-2.25"
                                placeholder="e.g. Industrial Area"
                                autocomplete="address-line2"
                                data-test="field-area"
                            />
                            <InputError :message="form.errors.area" />
                        </div>

                        <div>
                            <Label for="postal_code">Postal code</Label>
                            <Input
                                id="postal_code"
                                v-model="form.postal_code"
                                class="mt-2.25"
                                placeholder="e.g. 00100"
                                autocomplete="postal-code"
                                data-test="field-postal-code"
                            />
                            <InputError :message="form.errors.postal_code" />
                        </div>
                    </div>
                </div>
            </Card>

            <Card as="section" class="gap-0 p-0">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Additional information
                    </h2>
                </div>

                <div class="p-5">
                    <Label for="notes" class="sr-only">Notes</Label>
                    <!-- The shared textarea is `field-sizing-content`, so its
                         height comes from `min-h`; `rows` is only the fallback
                         for browsers without that support. Both are needed. -->
                    <Textarea
                        id="notes"
                        v-model="form.notes"
                        rows="8"
                        class="min-h-48"
                        placeholder="What they came in for, what they were quoted, anything worth knowing next time."
                        data-test="field-notes"
                    />
                    <InputError :message="form.errors.notes" />
                </div>
            </Card>

            <div class="flex items-center justify-end gap-3">
                <Button as-child variant="outline">
                    <Link :href="index().url">Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    :disabled="!canSubmit"
                    data-test="save-customer"
                >
                    {{ props.customer ? 'Save changes' : 'Add customer' }}
                </Button>
            </div>
        </form>
    </div>
</template>
