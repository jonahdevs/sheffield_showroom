<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ImageOff, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import DatePicker from '@/components/DatePicker.vue';
import InputError from '@/components/InputError.vue';
import OptionMultiCombobox from '@/components/OptionMultiCombobox.vue';
import OptionOrNewCombobox from '@/components/OptionOrNewCombobox.vue';
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
    products: App.Data.ProductOptionData[];
    types: { value: string; label: string }[];
    purposes: { value: string; label: string }[];
    sources: { value: string; label: string }[];
    interest_levels: { value: string; label: string }[];
    /* Whether a correction typed here reaches the customer's own record, or
       stops at the visit. The form says which, rather than accepting an edit
       it is going to drop. */
    can_update_customer: boolean;
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

const form = useForm({
    customer_id: props.visit?.customer_id ?? (null as number | null),
    customer_type: props.visit?.customer_type ?? 'individual',
    customer_name: props.visit?.customer_name ?? '',
    phone: props.visit?.phone ?? '',
    email: props.visit?.email ?? '',
    id_number: props.visit?.id_number ?? '',
    company_name: props.visit?.company_name ?? '',
    industry: props.visit?.industry ?? '',
    visited_on: props.visit?.visited_on ?? now.date,
    visited_time: props.visit?.visited_time ?? now.time,
    purpose: props.visit?.purpose ?? 'new_enquiry',
    /* Most people who walk into a showroom walked in. Pre-set rather than
       blank so the common case costs nobody a decision. */
    source: props.visit?.source ?? 'walk_in',
    /* Blank on a new visit, deliberately.
   
       It used to arrive pre-filled with whoever was signed in, on the
       assumption that the person typing is the person who took the visit. On
       a showroom floor that is often false - a manager writes up the day's
       calls at five o'clock - and a pre-filled name is one nobody rereads, so
       the wrong salesperson ends up credited with the visit. An empty box
       asks the question. */
    respondent: props.visit?.respondent ?? '',
    expected_follow_up_on: props.visit?.expected_follow_up_on ?? '',
    notes: props.visit?.notes ?? '',
    /* The whole row per product rather than a bare id: the table under the
       box shows a model number and takes an interest level, and both belong
       to the pick rather than to the catalogue. */
    products: (props.visit?.products ?? []) as App.Data.ProductOptionData[],
});

/**
 * What the picker holds, proxied onto the rows the table shows.
 *
 * The box speaks in ids and the table in rows, so a pick is turned into a row
 * here - keeping the level already set against anything still chosen, and
 * starting anything new at Medium so adding a product costs no decision.
 */
const pickedIds = computed<number[]>({
    get: () => form.products.map((product) => product.value),
    set: (ids) => {
        form.products = ids.map((id) => {
            const already = form.products.find(
                (product) => product.value === id,
            );

            if (already !== undefined) {
                return already;
            }

            const option = props.products.find(
                (product) => product.value === id,
            );

            return {
                value: id,
                label: option?.label ?? 'Unknown product',
                hint: option?.hint ?? null,
                image_url: option?.image_url ?? null,
                model_number: option?.model_number ?? null,
                quantity: 1,
                interest_level: 'medium',
            } satisfies App.Data.ProductOptionData;
        });
    },
});

/**
 * Whatever went wrong with the picks, wherever it is keyed.
 *
 * A quantity or a level fails against its own row - `products.2.quantity` -
 * and the one message under the box would never see it if it only looked for
 * `products`.
 */
const productError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;
    const key = Object.keys(errors).find((name) => name.startsWith('products'));

    return key === undefined ? undefined : errors[key];
});

/** Taken off the list. The catalogue still has it; this visit does not. */
function dropProduct(id: number) {
    form.products = form.products.filter((product) => product.value !== id);
}

const isCompany = computed(() => form.customer_type === 'company');

/** Whoever the form is attached to, or null while it describes somebody new. */
const linked = computed(
    () =>
        props.customers.find(
            (customer) => customer.value === form.customer_id,
        ) ?? null,
);

/**
 * Shown back read-only: attached to a record this person may not correct.
 *
 * A box you can type into but whose typing is thrown away is worse than one
 * you cannot, so when the edits have nowhere to go the fields say so by not
 * accepting any.
 */
const locked = computed(
    () => linked.value !== null && !props.can_update_customer,
);

/**
 * Somebody picked off the list.
 *
 * Filled from the record rather than from the label: the label is their name,
 * and it cannot say which company they came in for or what that company does.
 */
function choose(chosen: App.Data.CustomerOptionData) {
    form.customer_id = chosen.value;
    form.customer_type = chosen.type;
    form.customer_name = chosen.name ?? '';
    form.phone = chosen.hint ?? '';
    form.email = chosen.email ?? '';
    form.id_number = chosen.id_number ?? '';
    form.company_name = chosen.company_name ?? '';
    form.industry = chosen.industry ?? '';
    form.clearErrors();
}

/**
 * Nobody on the list. What was being searched for is their name.
 *
 * The typing is what makes this worth a row of its own: somebody who has just
 * typed out a name in the search box should not have to type it again into
 * the field below because the search found nothing.
 */
function startNew(name: string) {
    form.customer_id = null;

    if (name !== '') {
        form.customer_name = name;
    }

    form.clearErrors();
}

/**
 * Switching kind opens or closes the business half, and lets go of whoever
 * was attached.
 *
 * The person's name, number and ID are asked either way, so none of that is
 * disturbed - only the company they came in for, which is the thing the
 * answer changed. Letting go is because a record on file is one kind or the
 * other: saying otherwise is saying it is not that record. Picking somebody
 * sets this back the other way.
 */
function changeType(next: App.Enums.CustomerType) {
    form.customer_type = next;
    form.customer_id = null;

    if (next === 'individual') {
        form.company_name = '';
        form.industry = '';
    }

    form.clearErrors();
}

const heading = computed(() =>
    props.visit ? `Visit by ${props.visit.customer_label}` : 'New visit',
);

/** A number identifies the customer, so nothing can be filed without one. */
const canSubmit = computed(() => {
    if (form.processing || form.visited_on === '' || form.visited_time === '') {
        return false;
    }

    if (form.customer_name.trim() === '' || form.phone.trim() === '') {
        return false;
    }

    return !isCompany.value || form.company_name.trim() !== '';
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

  The customer half asks what the Customers form asks, in the same order and
  the same shape: who they are, then the business they came for if there is
  one. A person is recorded either way - somebody who says "I am Jonah and I
  have come for the company" is one customer with two facts about them.

  The name box is also the finder: type it, and whoever already answers to it
  comes up underneath. Most walk-ins are somebody new, and making a
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
            <!-- One column, in the order the call happened: who walked in,
                 what they came for, and what they were shown. The list of
                 products used to sit alongside; it reads better after the
                 write-up it belongs to than beside it.

                 Its own container, so the field rows measure this column
                 rather than the page - keyed on the page they would stay
                 two-across in a column half that wide. They go two across on
                 `@xl/main` and three on `@4xl/main`; Business stays at two,
                 having two fields and no use for a third column. -->
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
                        <div
                            class="flex flex-col gap-4 @xl/main:grid @xl/main:grid-cols-2 @xl/main:gap-x-5.5 @xl/main:gap-y-4.5 @4xl/main:grid-cols-3"
                        >
                            <!-- Leads the section because it decides whether
                                 a business section follows. It does not narrow
                                 the box below: whoever is looking somebody up
                                 has a name or a number, not a classification,
                                 and a search that hides the match they wanted
                                 is how one customer gets filed twice. Picking
                                 answers this instead. -->
                            <div>
                                <Label for="customer_type">
                                    Customer type
                                    <span class="text-primary">*</span>
                                </Label>
                                <Select
                                    :model-value="form.customer_type"
                                    :disabled="locked"
                                    @update:model-value="
                                        (value) =>
                                            changeType(
                                                value as App.Enums.CustomerType,
                                            )
                                    "
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

                            <!-- The name box is the finder too. Type it,
                                     and whoever already answers to it comes up
                                     underneath; if nobody does, New customer
                                     keeps what was typed and they are added on
                                     save. -->
                            <div>
                                <Label for="customer_name">
                                    Full name
                                    <span class="text-primary">*</span>
                                </Label>

                                <div class="mt-2.25">
                                    <Input
                                        v-if="locked"
                                        id="customer_name"
                                        :model-value="form.customer_name"
                                        readonly
                                        data-test="field-customer-name"
                                    />
                                    <OptionOrNewCombobox
                                        v-else
                                        id="customer_name"
                                        :model-value="form.customer_id"
                                        :name="form.customer_name"
                                        :options="props.customers"
                                        placeholder="Search or start a new one"
                                        search-placeholder="Name, company or phone number"
                                        new-label="New customer"
                                        data-test="field-customer-name"
                                        @pick="choose"
                                        @create="startNew"
                                    >
                                        <!-- The list is every customer, so
                                             the badge is what tells the two
                                             kinds apart in it. -->
                                        <template #meta="{ option }">
                                            <span
                                                class="shrink-0 rounded border px-1.5 py-0.5 text-[0.6875rem] text-faint"
                                            >
                                                {{
                                                    option.type === 'company'
                                                        ? 'Company'
                                                        : 'Individual'
                                                }}
                                            </span>
                                        </template>
                                    </OptionOrNewCombobox>
                                </div>

                                <InputError
                                    :message="form.errors.customer_name"
                                />

                                <!-- Read-only needs a reason, or the
                                         fields read as broken rather than as
                                         somebody else's to change. -->
                                <p
                                    v-if="locked"
                                    class="mt-2 text-xs text-faint"
                                >
                                    On file already - their details are shown as
                                    recorded and are edited under Customers.
                                </p>
                            </div>

                            <div>
                                <Label for="phone">
                                    Phone number
                                    <span class="text-primary">*</span>
                                </Label>
                                <div class="mt-2.25">
                                    <Input
                                        v-if="locked"
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
                                <Label for="email">Email</Label>
                                <Input
                                    id="email"
                                    v-model="form.email"
                                    type="email"
                                    class="mt-2.25"
                                    :readonly="locked"
                                    placeholder="Optional"
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
                                    :readonly="locked"
                                    placeholder="Optional"
                                    data-test="field-id-number"
                                />
                                <InputError :message="form.errors.id_number" />
                            </div>

                            <!-- How they came to be standing here. About
                                     the visit rather than the person, but it
                                     is answered while they are still being
                                     asked about, so it is asked here. -->
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

                <!-- Only for a company, and only the two fields the
                         company itself has. Everything about the person who
                         came in from it is already above. -->
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
                            class="flex flex-col gap-4 @xl/main:grid @xl/main:grid-cols-2 @xl/main:gap-x-5.5 @xl/main:gap-y-4.5"
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
                                    :readonly="locked"
                                    placeholder="e.g. Mwangi Builders Ltd"
                                    autocomplete="organization"
                                    data-test="field-company-name"
                                />
                                <InputError
                                    :message="form.errors.company_name"
                                />
                            </div>

                            <div>
                                <Label for="industry">Industry</Label>
                                <Input
                                    id="industry"
                                    v-model="form.industry"
                                    class="mt-2.25"
                                    :readonly="locked"
                                    placeholder="e.g. Construction"
                                    data-test="field-industry"
                                />
                                <InputError :message="form.errors.industry" />
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
                            class="flex flex-col gap-4 @xl/main:grid @xl/main:grid-cols-2 @xl/main:gap-x-5.5 @xl/main:gap-y-4.5 @4xl/main:grid-cols-3"
                        >
                            <!-- One control, because it is one answer: a visit
                                 happened at a moment, not on a date and
                                 separately at a time. The two form fields
                                 behind it stay as they are - the column joins
                                 them back together server-side, and splitting
                                 them here is what lets each keep its own
                                 rule. -->
                            <div>
                                <Label for="visited_at">
                                    Visit date and time
                                    <span class="text-primary">*</span>
                                </Label>
                                <div class="mt-2.25">
                                    <DatePicker
                                        id="visited_at"
                                        v-model="form.visited_on"
                                        v-model:time="form.visited_time"
                                        with-time
                                        max="today"
                                        data-test="field-visited-at"
                                    />
                                </div>
                                <InputError
                                    :message="
                                        form.errors.visited_on ??
                                        form.errors.visited_time
                                    "
                                />
                            </div>

                            <div>
                                <!-- The column and the enum behind this are
                                     still `purpose`; only what the floor is
                                     asked has changed. Renaming them to match
                                     would have moved a filter's query string
                                     key and every saved link built on it, for
                                     a word nobody sees. -->
                                <Label for="purpose">
                                    Nature of visit
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
                                <InputError :message="form.errors.purpose" />
                            </div>

                            <div>
                                <Label for="respondent">
                                    Respondent
                                    <span class="text-primary">*</span>
                                </Label>
                                <Input
                                    id="respondent"
                                    v-model="form.respondent"
                                    class="mt-2.25"
                                    placeholder="Who took the visit"
                                    data-test="field-respondent"
                                />
                                <InputError :message="form.errors.respondent" />
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
                                    :message="form.errors.expected_follow_up_on"
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

                <Card as="section" class="gap-0 p-0">
                    <div class="border-b border-divider px-5 py-3.5">
                        <h2
                            class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                        >
                            Interested products
                        </h2>
                    </div>

                    <div class="p-5">
                        <Label for="products" class="sr-only">
                            Interested products
                        </Label>
                        <!-- No chips under the box: the table below says the
                             same names and three more things besides. -->
                        <OptionMultiCombobox
                            id="products"
                            v-model="pickedIds"
                            :options="props.products"
                            :selected="form.products"
                            hide-chosen
                            placeholder="Add what they were shown"
                            search-placeholder="Product name or SKU"
                            empty-text="No product matches that."
                            data-test="field-products"
                        />
                        <InputError :message="productError" />
                    </div>

                    <!-- What was picked, in rows rather than chips: each one
                         carries a model number to read back and a level to
                         set, and neither fits on a chip. -->
                    <div
                        v-if="form.products.length > 0"
                        class="overflow-x-auto border-t border-divider"
                    >
                        <!-- Semibold rather than bold: the lead cell of every row below
                             is itself text-xs font-bold, so a bold header would read as
                             one more row instead of the label for all of them. -->
                        <div
                            class="grid min-w-[760px] grid-cols-[minmax(0,1fr)_minmax(150px,0.2fr)_96px_minmax(150px,0.2fr)_44px] items-center gap-4 border-b border-divider bg-muted/50 px-5 py-3 text-xs font-semibold"
                        >
                            <span>Product</span>
                            <span>Model</span>
                            <span>Quantity</span>
                            <span>Interest level</span>
                            <span class="sr-only">Actions</span>
                        </div>

                        <div class="divide-y divide-divider">
                            <div
                                v-for="(product, index) in form.products"
                                :key="product.value"
                                class="grid min-w-[760px] grid-cols-[minmax(0,1fr)_minmax(150px,0.2fr)_96px_minmax(150px,0.2fr)_44px] items-center gap-4 px-5 py-2.5"
                                :data-test="`picked-${product.value}`"
                            >
                                <div class="flex min-w-0 items-center gap-3">
                                    <span
                                        class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded bg-muted"
                                        aria-hidden="true"
                                    >
                                        <img
                                            v-if="product.image_url"
                                            :src="product.image_url"
                                            alt=""
                                            loading="lazy"
                                            class="size-full object-contain"
                                        />
                                        <ImageOff
                                            v-else
                                            class="size-3.5 text-faint"
                                        />
                                    </span>

                                    <div class="min-w-0">
                                        <p
                                            class="truncate text-xs font-bold"
                                            :title="product.label"
                                        >
                                            {{ product.label }}
                                        </p>
                                        <p
                                            v-if="product.hint"
                                            class="mt-0.5 truncate font-mono text-xs text-faint"
                                        >
                                            {{ product.hint }}
                                        </p>
                                    </div>
                                </div>

                                <span
                                    class="truncate font-mono text-xs"
                                    :class="
                                        product.model_number ? '' : 'text-faint'
                                    "
                                    :title="product.model_number ?? undefined"
                                >
                                    {{ product.model_number ?? '--' }}
                                </span>

                                <Input
                                    v-model.number="
                                        form.products[index].quantity
                                    "
                                    type="number"
                                    min="1"
                                    inputmode="numeric"
                                    class="py-1.5 text-center tabular-nums"
                                    :aria-label="`How many ${product.label}`"
                                    :data-test="`quantity-${product.value}`"
                                />

                                <Select
                                    v-model="
                                        form.products[index].interest_level
                                    "
                                >
                                    <SelectTrigger
                                        class="w-full"
                                        size="sm"
                                        :aria-label="`Interest in ${product.label}`"
                                        :data-test="`interest-${product.value}`"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="level in props.interest_levels"
                                            :key="level.value"
                                            :value="level.value"
                                        >
                                            {{ level.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>

                                <Button
                                    type="button"
                                    variant="quiet"
                                    size="icon"
                                    :aria-label="`Remove ${product.label}`"
                                    :data-test="`remove-${product.value}`"
                                    @click="dropProduct(product.value)"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>
                    </div>
                </Card>
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
