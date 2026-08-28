<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, ref } from 'vue';
import DatePicker from '@/components/DatePicker.vue';
import InputError from '@/components/InputError.vue';
import OptionCombobox from '@/components/OptionCombobox.vue';
import OptionMultiCombobox from '@/components/OptionMultiCombobox.vue';
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
import { dashboard } from '@/routes';
import { index, store, update } from '@/routes/admin/visits';

const props = defineProps<{
    visit: App.Data.VisitFormData | null;
    customers: App.Data.CustomerOptionData[];
    products: App.Data.OptionData[];
    types: { value: string; label: string }[];
    purposes: { value: string; label: string }[];
    sources: { value: string; label: string }[];
}>();

/**
 * Today and the hour on the clock, as the two fields want them.
 *
 * Built from the browser's own date rather than the server's: the salesperson
 * is standing in the showroom, and it is their afternoon the visit happened
 * in.
 */
function nowParts(): { date: string; time: string } {
    const now = new Date();
    const pad = (value: number) => String(value).padStart(2, '0');

    return {
        date: `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`,
        time: `${pad(now.getHours())}:${pad(now.getMinutes())}`,
    };
}

const now = nowParts();

/*
  Whoever is signed in took the visit, until they say otherwise. A manager
  writing up somebody else's afternoon changes it; the salesperson logging
  their own does not have to think about the field at all.
*/
const page = usePage();

const form = useForm({
    customer_id: props.visit?.customer_id ?? (null as number | null),
    customer_type: props.visit?.customer_type ?? 'individual',
    customer_name: props.visit?.customer_name ?? '',
    company_name: props.visit?.company_name ?? '',
    phone: props.visit?.phone ?? '',
    email: props.visit?.email ?? '',
    visited_on: props.visit?.visited_on ?? now.date,
    visited_time: props.visit?.visited_time ?? now.time,
    purpose: props.visit?.purpose ?? 'new_enquiry',
    /* Most people who walk into a showroom walked in. Pre-set rather than
       blank so the common case costs nobody a decision. */
    source: props.visit?.source ?? 'walk_in',
    respondent: props.visit?.respondent ?? page.props.auth.user.name,
    expected_follow_up_on: props.visit?.expected_follow_up_on ?? '',
    duration_minutes: props.visit?.duration_minutes ?? (null as number | null),
    notes: props.visit?.notes ?? '',
    product_ids: props.visit?.product_ids ?? ([] as number[]),
});

/**
 * Somebody already on file, chosen off the list.
 *
 * Their details are shown back read-only rather than left editable: a visit is
 * not the place to quietly rewrite a customer record, and a box you can type
 * into but whose typing is ignored is worse than one you cannot.
 */
const isExisting = computed(() => form.customer_id !== null);

const isCompany = computed(() => form.customer_type === 'company');

/** What the picker holds, kept apart so clearing it does not clear the form. */
const picked = ref<number | null>(props.visit?.customer_id ?? null);

function choose(value: number | null) {
    picked.value = value;

    if (value === null) {
        return;
    }

    const chosen = props.customers.find((customer) => customer.value === value);

    if (chosen === undefined) {
        return;
    }

    /* Filled from the record rather than from the label, so a company comes
       back as a company: the label alone cannot say which of the two name
       columns it was built from. */
    form.customer_id = value;
    form.customer_type = chosen.type;
    form.customer_name = chosen.name ?? '';
    form.company_name = chosen.company_name ?? '';
    form.phone = chosen.hint ?? '';
    form.email = chosen.email ?? '';
    form.clearErrors();
}

/** Back to an empty set of fields, ready for somebody not yet on file. */
function clearCustomer() {
    picked.value = null;
    form.customer_id = null;
    form.customer_type = 'individual';
    form.customer_name = '';
    form.company_name = '';
    form.phone = '';
    form.email = '';
    form.clearErrors();
}

const heading = computed(() =>
    props.visit
        ? `Visit by ${props.visit.customer_label}`
        : 'New customer visit',
);

/*
  The box holds a string and the column holds a number or nothing. Cleared
  reads back as null rather than 0, because an unrecorded duration is not a
  visit of zero length and an average has to tell those apart.
*/
const duration = computed<string>({
    get: () =>
        form.duration_minutes === null ? '' : String(form.duration_minutes),
    set: (value) => {
        const trimmed = value.trim();

        form.duration_minutes = trimmed === '' ? null : Number(trimmed);
    },
});

/** A number identifies the customer, so nothing can be filed without one. */
const canSubmit = computed(() => {
    if (form.processing || form.visited_on === '' || form.visited_time === '') {
        return false;
    }

    if (isExisting.value) {
        return true;
    }

    const named = isCompany.value
        ? form.company_name.trim() !== ''
        : form.customer_name.trim() !== '';

    return named && form.phone.trim() !== '';
});

function submit() {
    if (props.visit) {
        form.patch(update(props.visit.id).url);

        return;
    }

    form.post(store().url);
}

defineOptions({
    layout: (page: { visit: App.Data.VisitFormData | null }) => ({
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Visits', href: index().url },
            { title: page.visit ? 'Edit' : 'Create' },
        ],
    }),
});
</script>

<!--
  Writing up a call at the showroom, in the order it happened: who walked in,
  then what they came for, with what they were shown alongside.

  The customer is found or typed. Most walk-ins are somebody new, and making a
  salesperson leave the form to add them first is how visits stop being logged
  at all.
-->
<template>
    <Head :title="heading" />

    <div class="flex flex-col gap-5">
        <div>
            <h1 class="text-2xl leading-tight">{{ heading }}</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Who came in, what they came for, and what they were shown.
                Everything past that can be filled in as you remember it.
            </p>
        </div>

        <form class="flex flex-col gap-5" @submit.prevent="submit">
            <!-- Two columns once the page has the room for them. What was
                 shown is a running list built while the write-up is typed, so
                 it sits alongside rather than below - and stays put as the
                 left column scrolls. -->
            <div
                class="grid items-start gap-5 @4xl/page:grid-cols-[minmax(0,1fr)_24rem]"
            >
                <!-- Its own container, so the fields inside measure this
                     column rather than the page. Keyed on the page they would
                     stay two-across in a column half that wide. -->
                <div class="@container/main flex flex-col gap-5">
                    <Card as="section" class="gap-0 p-0">
                        <div class="border-b border-divider px-5 py-3.5">
                            <h2
                                class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                            >
                                Customer information
                            </h2>
                        </div>

                        <div class="p-5">
                            <div>
                                <Label for="find_customer">
                                    Find an existing customer
                                </Label>
                                <div class="mt-2.25 flex items-center gap-2.5">
                                    <div class="min-w-0 flex-1">
                                        <OptionCombobox
                                            id="find_customer"
                                            :model-value="picked"
                                            :options="props.customers"
                                            placeholder="Search name, company or phone"
                                            search-placeholder="Name, company or phone number"
                                            empty-text="Nobody on file matches. Fill the details in below."
                                            data-test="field-customer"
                                            @update:model-value="choose"
                                        />
                                    </div>

                                    <Button
                                        v-if="isExisting"
                                        type="button"
                                        variant="quiet"
                                        size="icon"
                                        aria-label="Clear the chosen customer"
                                        data-test="clear-customer"
                                        @click="clearCustomer"
                                    >
                                        <X />
                                    </Button>
                                </div>
                                <InputError
                                    :message="form.errors.customer_id"
                                />

                                <p class="mt-2 text-xs text-faint">
                                    {{
                                        isExisting
                                            ? 'On file already - their details are shown below and are edited under Customers.'
                                            : 'Or fill in the details below and they will be added on save.'
                                    }}
                                </p>
                            </div>

                            <div
                                class="mt-5 flex flex-col gap-4 @xl/main:grid @xl/main:grid-cols-2 @xl/main:gap-x-5.5 @xl/main:gap-y-4.5"
                            >
                                <div>
                                    <Label for="customer_type">
                                        Customer type
                                        <span class="text-primary">*</span>
                                    </Label>
                                    <Select
                                        v-model="form.customer_type"
                                        :disabled="isExisting"
                                    >
                                        <SelectTrigger
                                            id="customer_type"
                                            class="mt-2.25 w-full"
                                            data-test="field-customer-type"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="type in props.types"
                                                :key="type.value"
                                                :value="type.value"
                                            >
                                                {{ type.label }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        :message="form.errors.customer_type"
                                    />
                                </div>

                                <div>
                                    <Label for="customer_name">
                                        Customer name
                                        <span
                                            v-if="!isCompany"
                                            class="text-primary"
                                            >*</span
                                        >
                                    </Label>
                                    <Input
                                        id="customer_name"
                                        v-model="form.customer_name"
                                        class="mt-2.25"
                                        :readonly="isExisting"
                                        placeholder="e.g. Achieng Odhiambo"
                                        data-test="field-customer-name"
                                    />
                                    <InputError
                                        :message="form.errors.customer_name"
                                    />
                                </div>

                                <div>
                                    <Label for="phone">
                                        Phone number
                                        <span class="text-primary">*</span>
                                    </Label>
                                    <div class="mt-2.25">
                                        <Input
                                            v-if="isExisting"
                                            id="phone"
                                            :model-value="form.phone"
                                            readonly
                                            data-test="field-phone"
                                        />
                                        <PhoneInput
                                            v-else
                                            id="phone"
                                            v-model="form.phone"
                                            data-test="field-phone"
                                        />
                                    </div>
                                    <InputError :message="form.errors.phone" />
                                </div>

                                <div>
                                    <Label for="email">Email address</Label>
                                    <Input
                                        id="email"
                                        v-model="form.email"
                                        type="email"
                                        class="mt-2.25"
                                        :readonly="isExisting"
                                        placeholder="Optional"
                                        data-test="field-email"
                                    />
                                    <InputError :message="form.errors.email" />
                                </div>

                                <div>
                                    <Label for="company_name">
                                        Company / organisation
                                        <span
                                            v-if="isCompany"
                                            class="text-primary"
                                            >*</span
                                        >
                                    </Label>
                                    <Input
                                        id="company_name"
                                        v-model="form.company_name"
                                        class="mt-2.25"
                                        :readonly="isExisting"
                                        placeholder="Optional"
                                        data-test="field-company-name"
                                    />
                                    <InputError
                                        :message="form.errors.company_name"
                                    />
                                </div>

                                <div>
                                    <Label for="source">
                                        Source
                                        <span class="text-primary">*</span>
                                    </Label>
                                    <Select v-model="form.source">
                                        <SelectTrigger
                                            id="source"
                                            class="mt-2.25 w-full"
                                            data-test="field-source"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="source in props.sources"
                                                :key="source.value"
                                                :value="source.value"
                                            >
                                                {{ source.label }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError :message="form.errors.source" />
                                </div>
                            </div>
                        </div>
                    </Card>

                    <Card as="section" class="gap-0 p-0">
                        <div class="border-b border-divider px-5 py-3.5">
                            <h2
                                class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                            >
                                Visit information
                            </h2>
                        </div>

                        <div class="p-5">
                            <div
                                class="flex flex-col gap-4 @xl/main:grid @xl/main:grid-cols-2 @xl/main:gap-x-5.5 @xl/main:gap-y-4.5"
                            >
                                <div>
                                    <Label for="visited_on">
                                        Visit date
                                        <span class="text-primary">*</span>
                                    </Label>
                                    <div class="mt-2.25">
                                        <DatePicker
                                            id="visited_on"
                                            v-model="form.visited_on"
                                            max="today"
                                            data-test="field-visited-on"
                                        />
                                    </div>
                                    <InputError
                                        :message="form.errors.visited_on"
                                    />
                                </div>

                                <div>
                                    <Label for="visited_time">
                                        Visit time
                                        <span class="text-primary">*</span>
                                    </Label>
                                    <Input
                                        id="visited_time"
                                        v-model="form.visited_time"
                                        type="time"
                                        class="mt-2.25"
                                        data-test="field-visited-time"
                                    />
                                    <InputError
                                        :message="form.errors.visited_time"
                                    />
                                </div>

                                <div>
                                    <Label for="purpose">
                                        Purpose of visit
                                        <span class="text-primary">*</span>
                                    </Label>
                                    <Select v-model="form.purpose">
                                        <SelectTrigger
                                            id="purpose"
                                            class="mt-2.25 w-full"
                                            data-test="field-purpose"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="purpose in props.purposes"
                                                :key="purpose.value"
                                                :value="purpose.value"
                                            >
                                                {{ purpose.label }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        :message="form.errors.purpose"
                                    />
                                </div>

                                <div>
                                    <Label for="respondent">
                                        Assigned staff
                                        <span class="text-primary">*</span>
                                    </Label>
                                    <Input
                                        id="respondent"
                                        v-model="form.respondent"
                                        class="mt-2.25"
                                        placeholder="Who took the visit"
                                        data-test="field-respondent"
                                    />
                                    <InputError
                                        :message="form.errors.respondent"
                                    />
                                </div>

                                <div>
                                    <Label for="expected_follow_up_on">
                                        Expected follow-up
                                    </Label>
                                    <div class="mt-2.25">
                                        <DatePicker
                                            id="expected_follow_up_on"
                                            v-model="form.expected_follow_up_on"
                                            placeholder="No follow-up planned"
                                            data-test="field-follow-up"
                                        />
                                    </div>
                                    <InputError
                                        :message="
                                            form.errors.expected_follow_up_on
                                        "
                                    />
                                </div>

                                <div>
                                    <Label for="duration_minutes">
                                        Duration
                                    </Label>
                                    <div class="relative mt-2.25">
                                        <Input
                                            id="duration_minutes"
                                            v-model="duration"
                                            type="number"
                                            inputmode="numeric"
                                            min="1"
                                            max="720"
                                            class="pr-16"
                                            placeholder="45"
                                            data-test="field-duration"
                                        />
                                        <span
                                            class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-faint"
                                        >
                                            minutes
                                        </span>
                                    </div>
                                    <InputError
                                        :message="form.errors.duration_minutes"
                                    />
                                </div>
                            </div>

                            <div class="mt-5">
                                <Label for="notes">Visit notes</Label>
                                <Textarea
                                    id="notes"
                                    v-model="form.notes"
                                    rows="6"
                                    class="mt-2.25 min-h-36"
                                    placeholder="What they were after, what they said about it, anything worth knowing before they come back."
                                    data-test="field-notes"
                                />
                                <InputError :message="form.errors.notes" />
                            </div>
                        </div>
                    </Card>
                </div>

                <aside
                    class="flex flex-col gap-5 @4xl/page:sticky @4xl/page:top-5"
                >
                    <Card as="section" class="gap-0 p-0">
                        <div class="border-b border-divider px-5 py-3.5">
                            <h2
                                class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                            >
                                Products viewed
                            </h2>
                        </div>

                        <div class="p-5">
                            <Label for="product_ids" class="sr-only">
                                Products viewed
                            </Label>
                            <OptionMultiCombobox
                                id="product_ids"
                                v-model="form.product_ids"
                                :options="props.products"
                                :selected="props.visit?.products ?? []"
                                placeholder="Add what they were shown"
                                search-placeholder="Product name or SKU"
                                empty-text="No product matches that."
                                data-test="field-products"
                            />
                            <InputError :message="form.errors.product_ids" />
                        </div>
                    </Card>
                </aside>
            </div>

            <div class="flex items-center justify-end gap-3">
                <Button as-child variant="quiet">
                    <Link :href="index().url">Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    :disabled="!canSubmit"
                    data-test="save-visit"
                >
                    {{ props.visit ? 'Save changes' : 'Add visit' }}
                </Button>
            </div>
        </form>
    </div>
</template>
