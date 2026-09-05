<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ImageOff, Trash2 } from '@lucide/vue';
import { computed, ref, watch, watchEffect } from 'vue';
import { toast } from 'vue-sonner';
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
import {
    chosenOption,
    focusIf,
    openOnStored,
    storedChoice,
} from '@/lib/options';
import { dashboard } from '@/routes';
import { index, store, update } from '@/routes/admin/visits';

const props = defineProps<{
    visit: App.Data.VisitFormData | null;
    customers: App.Data.CustomerOptionData[];
    products: App.Data.ProductOptionData[];
    /* One question, not two. The customer arm is split by customer type
       because that distinction only means something to somebody buying;
       `visitor_type` and `customer_type` on the form are derived from it. */
    visitor_types: {
        value: string;
        label: string;
        hint: string;
        visitor_type: string;
        customer_type: string | null;
    }[];
    segments: { value: string; label: string }[];
    purposes: { value: string; label: string }[];
    departments: { value: string; label: string }[];
    sources: { value: string; label: string }[];
    interest_levels: { value: string; label: string }[];
    /* Whether a correction typed here reaches the customer's own record or
       stops at the visit. Drives `locked` below. */
    can_update_customer: boolean;
}>();

/**
 * Today and the hour, in the shapes the date and time fields read.
 *
 * Assembled from the local `get*` parts rather than slicing `toISOString()`,
 * which is UTC and would file an evening visit under tomorrow's date.
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
    /* Both derived from `visitorChoice` by the watcher below - never assigned
       directly, or the next tick overwrites what was set. */
    visitor_type: (props.visit?.visitor_type ?? 'customer') as string,
    /* Widened deliberately: it is emptied for anybody who is not buying, and
       `VisitRequest` refuses a customer type from them. */
    customer_type: (props.visit?.customer_type ?? 'individual') as string,
    visitor_name: props.visit?.visitor_name ?? '',
    phone: props.visit?.phone ?? '',
    email: props.visit?.email ?? '',
    id_number: props.visit?.id_number ?? '',
    organisation: props.visit?.organisation ?? '',
    segment: props.visit?.segment ?? '',
    visited_on: props.visit?.visited_on ?? now.date,
    visited_time: props.visit?.visited_time ?? now.time,
    purpose: props.visit?.purpose ?? 'enquiry',
    department: props.visit?.department ?? 'showroom',
    source: props.visit?.source ?? 'walk_in',
    referred_by: props.visit?.referred_by ?? '',
    /* Blank on a new visit, deliberately - do not "fix" this to the signed-in
       user. A manager often writes up the day's calls, and a pre-filled name
       nobody rereads credits the wrong salesperson. */
    respondent: props.visit?.respondent ?? '',
    expected_follow_up_on: props.visit?.expected_follow_up_on ?? '',
    notes: props.visit?.notes ?? '',
    products: (props.visit?.products ?? []) as App.Data.ProductOptionData[],
});

/**
 * The picker speaks in ids, `form.products` in rows. The setter must reuse the
 * existing row for anything still chosen, or re-picking wipes the quantity and
 * interest level already set against it.
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
 * A quantity or level fails against its own row - `products.2.quantity` - so a
 * lookup of plain `products` would miss it.
 */
const productError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;
    const key = Object.keys(errors).find((name) => name.startsWith('products'));

    return key === undefined ? undefined : errors[key];
});

function dropProduct(id: number) {
    form.products = form.products.filter((product) => product.value !== id);
}

/* The one question reception is asked. Its value is the menu's composite -
   `customer_individual`, `supplier` - and the watcher below splits it back into
   the two fields the server takes. */
const visitorChoice = ref(
    props.visit === null
        ? 'customer_individual'
        : (props.visitor_types.find(
              (option) =>
                  option.visitor_type === props.visit?.visitor_type &&
                  option.customer_type === (props.visit?.customer_type ?? null),
          )?.value ?? 'customer_individual'),
);

const chosenVisitor = computed(
    () =>
        props.visitor_types.find(
            (option) => option.value === visitorChoice.value,
        ) ?? props.visitor_types[0],
);

watchEffect(() => {
    form.visitor_type = chosenVisitor.value.visitor_type;
    form.customer_type = chosenVisitor.value.customer_type ?? '';
});

/* Only a customer gets a record of their own, so only a customer is matched on
   their telephone and only a customer has anywhere to keep an email, an ID
   number or a trade. Everybody else is written on the visit - see `VisitorType`.
   `VisitRequest` refuses the customer-only fields outright rather than dropping
   them silently, so anything it would refuse is not shown. */
const isCustomer = computed(() => form.visitor_type === 'customer');

const isCompany = computed(
    () => isCustomer.value && form.customer_type === 'company',
);

const visitorHint = computed(() => chosenVisitor.value.hint);

const linked = computed(
    () =>
        props.customers.find(
            (customer) => customer.value === form.customer_id,
        ) ?? null,
);

/**
 * Attached to a customer this person may not correct, so the customer fields
 * go read-only: an edit typed into them would be silently dropped on save.
 */
const locked = computed(
    () => linked.value !== null && !props.can_update_customer,
);

/* The picker only ever offers customers, so choosing one is also an answer to
   the question above. */
function choose(chosen: App.Data.CustomerOptionData) {
    form.customer_id = chosen.value;
    visitorChoice.value = `customer_${chosen.type}`;
    form.visitor_name = chosen.name ?? '';
    form.phone = chosen.hint ?? '';
    form.email = chosen.email ?? '';
    form.id_number = chosen.id_number ?? '';
    form.organisation = chosen.company_name ?? '';
    openSegment(chosen.segment);
    form.clearErrors();
}

/** Nobody on the list: what was being searched for becomes their name. */
function startNew(name: string) {
    form.customer_id = null;

    if (name !== '') {
        form.visitor_name = name;
    }

    form.clearErrors();
}

/**
 * Any change to the answer drops `customer_id`. A record on file is a customer
 * of one kind, so the attachment cannot survive the visitor becoming a courier
 * or the other kind of customer - and `VisitRequest` refuses an id outright
 * from anybody who was not buying. The name, phone and organisation typed so
 * far stay; only what now has nowhere to go is cleared.
 */
function changeVisitor(next: string) {
    visitorChoice.value = next;
    form.customer_id = null;

    /* Read off `next`, not off `isCustomer` / `isCompany`: those follow
       `form.visitor_type`, which the watcher above only sets on the next flush,
       so here they still describe the choice being replaced. Clearing on a
       stale answer leaves a segment or an email on a courier, and
       `VisitRequest` refuses both outright. */
    const chosen = props.visitor_types.find((option) => option.value === next);

    if (chosen?.customer_type !== 'company') {
        openSegment(null);
    }

    if (chosen?.visitor_type !== 'customer') {
        form.email = '';
        form.id_number = '';
    }

    form.clearErrors();
}

const heading = computed(() =>
    props.visit ? `Visit by ${props.visit.visitor_label}` : 'New visit',
);

const { choice: purposeChoice, other: purposeOther } = openOnStored(
    props.purposes,
    props.visit?.purpose,
    'enquiry',
);

const { choice: departmentChoice, other: departmentOther } = openOnStored(
    props.departments,
    props.visit?.department,
    'showroom',
);

const { choice: sourceChoice, other: sourceOther } = openOnStored(
    props.sources,
    props.visit?.source,
    'walk_in',
);

/* Blank rather than a default: a segment nobody recorded must stay unrecorded. */
const { choice: segmentChoice, other: segmentOther } = openOnStored(
    props.segments,
    props.visit?.segment,
    '',
);

/* Picking a customer, or dropping to an individual, re-opens the pair on what
   that record holds - writing `form.segment` directly would be overwritten by
   the watcher below on the next tick. */
function openSegment(stored: string | null | undefined) {
    const opened = storedChoice(props.segments, stored, '');

    segmentChoice.value = opened.choice;
    segmentOther.value = opened.other;
}

watchEffect(() => {
    form.purpose = chosenOption(purposeChoice.value, purposeOther.value);
    form.department = chosenOption(
        departmentChoice.value,
        departmentOther.value,
    );
    form.source = chosenOption(sourceChoice.value, sourceOther.value);
    form.segment = chosenOption(segmentChoice.value, segmentOther.value);
});

const isReferral = computed(() => form.source === 'referral');

/* Each Other box, and the Referred-by beside it, take the cursor as they
   appear. */
const purposeOtherBox = ref<HTMLElement | null>(null);
const departmentOtherBox = ref<HTMLElement | null>(null);
const sourceOtherBox = ref<HTMLElement | null>(null);
const segmentOtherBox = ref<HTMLElement | null>(null);
const referredByBox = ref<HTMLElement | null>(null);

function onPurposeChosen(chosen: unknown) {
    focusIf(chosen === 'other', purposeOtherBox);
}

function onDepartmentChosen(chosen: unknown) {
    focusIf(chosen === 'other', departmentOtherBox);
}

function onSegmentChosen(chosen: unknown) {
    focusIf(chosen === 'other', segmentOtherBox);
}

/* Referral reveals a box of its own, so the source select has two to place. */
function onSourceChosen(chosen: unknown) {
    focusIf(chosen === 'other', sourceOtherBox);
    focusIf(chosen === 'referral', referredByBox);
}

/* `referred_by` is refused for every other source, so it has to leave with the
   choice rather than linger from an earlier save. */
watch(isReferral, (referral) => {
    if (!referral) {
        form.referred_by = '';
    }
});

/*
  Only ever guards against a second submission of the same form. What is
  missing is the server's answer, not this button's: a disabled Save leaves
  somebody staring at a form with no idea which field is holding it shut.
*/
const canSubmit = computed(() => !form.processing);

/* The fields carry their own messages; this is so the answer is not offscreen
   when the page is longer than the window. */
function reportInvalid() {
    toast.error('Some details are missing. Check the highlighted fields.');
}

function submit() {
    /*
      The picker's rows are `ProductOptionData`, keyed by `value`, while
      `VisitRequest` validates `products.*.id`. Posting the rows as they stand
      fails with "The products.0.id field is required" against a product that
      is plainly selected, so the id is renamed here and the display-only
      fields are dropped rather than sent to be discarded by `validated()`.
    */
    form.transform((data) => ({
        ...data,
        products: data.products.map((product) => ({
            id: product.value,
            quantity: product.quantity,
            interest_level: product.interest_level,
        })),
    }));

    if (props.visit) {
        form.patch(update(props.visit.id).url, { onError: reportInvalid });

        return;
    }

    form.post(store().url, { onError: reportInvalid });
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
            <div class="@container/main flex flex-col gap-5">
                <Card as="section" class="gap-0 p-0">
                    <div class="border-b border-divider px-5 py-3.5">
                        <h2
                            class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                        >
                            Visitor details
                        </h2>
                    </div>

                    <div class="p-5">
                        <div
                            class="flex flex-col gap-4 @xl/main:grid @xl/main:grid-cols-2 @xl/main:gap-x-5.5 @xl/main:gap-y-4.5 @4xl/main:grid-cols-3"
                        >
                            <!-- One question, not two: whether somebody
                                 buys for themselves or for a firm only means
                                 anything if they are buying at all. Deliberately
                                 does not filter the name search below - hiding
                                 the match somebody wanted is how one person
                                 gets filed twice. -->
                            <div>
                                <Label for="visitor_type">
                                    Who came in
                                    <span class="text-primary">*</span>
                                </Label>
                                <Select
                                    :model-value="visitorChoice"
                                    :disabled="locked"
                                    @update:model-value="
                                        (value) => changeVisitor(value as string)
                                    "
                                >
                                    <SelectTrigger
                                        id="visitor_type"
                                        class="mt-2.25 w-full"
                                        data-test="field-visitor-type"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="visitor in props.visitor_types"
                                            :key="visitor.value"
                                            :value="visitor.value"
                                            :data-test="`visitor-${visitor.value}`"
                                        >
                                            {{ visitor.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p class="mt-2 text-xs text-faint">
                                    {{ visitorHint }}
                                </p>
                                <InputError
                                    :message="form.errors.visitor_type"
                                />
                                <InputError
                                    :message="form.errors.customer_type"
                                />
                            </div>

                            <div>
                                <Label for="visitor_name">
                                    Full name
                                    <span class="text-primary">*</span>
                                </Label>

                                <div class="mt-2.25">
                                    <!-- A plain box for anybody who is not
                                         buying: the list holds customers, and
                                         picking one off it would file a
                                         courier's call against them. -->
                                    <Input
                                        v-if="locked || !isCustomer"
                                        id="visitor_name"
                                        v-model="form.visitor_name"
                                        :readonly="locked"
                                        placeholder="Who came in"
                                        data-test="field-visitor-name"
                                    />
                                    <OptionOrNewCombobox
                                        v-else
                                        id="visitor_name"
                                        :model-value="form.customer_id"
                                        :name="form.visitor_name"
                                        :options="props.customers"
                                        placeholder="Search or start a new one"
                                        search-placeholder="Name, company or phone number"
                                        new-label="New customer"
                                        data-test="field-visitor-name"
                                        @pick="choose"
                                        @create="startNew"
                                    >
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
                                    :message="form.errors.visitor_name"
                                />

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
                                    <span
                                        v-if="isCustomer"
                                        class="text-primary"
                                    >
                                        *
                                    </span>
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
                                <p
                                    v-if="!isCustomer && !locked"
                                    class="mt-2 text-xs text-faint"
                                >
                                    Optional. Nobody who is not a customer is
                                    looked up by it.
                                </p>
                                <InputError :message="form.errors.phone" />
                            </div>

                            <!-- Only a customer has a record to keep these on,
                                 and `VisitRequest` refuses them from anybody
                                 else rather than dropping them silently. -->
                            <div v-if="isCustomer">
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

                            <div v-if="isCustomer">
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

                            <!-- The firm behind them: the customer's own
                                 company when they are buying for one, and who
                                 sent them when they are not. -->
                            <div v-if="!isCustomer || isCompany">
                                <Label for="organisation">
                                    {{
                                        isCompany
                                            ? 'Company name'
                                            : 'Organisation'
                                    }}
                                    <span v-if="isCompany" class="text-primary">
                                        *
                                    </span>
                                </Label>
                                <Input
                                    id="organisation"
                                    v-model="form.organisation"
                                    class="mt-2.25"
                                    :readonly="locked"
                                    :placeholder="
                                        isCompany
                                            ? 'e.g. Mwangi Builders Ltd'
                                            : 'Optional - who sent them'
                                    "
                                    autocomplete="organization"
                                    data-test="field-organisation"
                                />
                                <InputError
                                    :message="form.errors.organisation"
                                />
                            </div>

                            <div>
                                <Label for="source">
                                    Source
                                    <span class="text-primary">*</span>
                                </Label>
                                <Select
                                    v-model="sourceChoice"
                                    @update:model-value="onSourceChosen"
                                >
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

                                <Input
                                    v-if="sourceChoice === 'other'"
                                    id="source-other"
                                    ref="sourceOtherBox"
                                    v-model="sourceOther"
                                    class="mt-2.25"
                                    maxlength="120"
                                    placeholder="How did they hear about us?"
                                    aria-label="Describe how they found the showroom"
                                    data-test="field-source-other"
                                />

                                <InputError :message="form.errors.source" />
                            </div>

                            <!-- A referral stays filed as "Referral"; this is
                                 the second fact of who made it. -->
                            <div v-if="isReferral">
                                <Label for="referred_by">
                                    Referred by
                                    <span class="text-primary">*</span>
                                </Label>
                                <Input
                                    id="referred_by"
                                    ref="referredByBox"
                                    v-model="form.referred_by"
                                    class="mt-2.25"
                                    maxlength="120"
                                    placeholder="Who sent them"
                                    data-test="field-referred-by"
                                />
                                <InputError
                                    :message="form.errors.referred_by"
                                />
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
                            class="flex flex-col gap-4 @xl/main:grid @xl/main:grid-cols-2 @xl/main:gap-x-5.5 @xl/main:gap-y-4.5"
                        >
                            <div>
                                <Label for="segment">Segment</Label>
                                <Select
                                    v-model="segmentChoice"
                                    :disabled="locked"
                                    @update:model-value="onSegmentChosen"
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

                                <Input
                                    v-if="segmentChoice === 'other'"
                                    id="segment-other"
                                    v-model="segmentOther"
                                    class="mt-2.25"
                                    maxlength="120"
                                    :readonly="locked"
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
                            Visit information
                        </h2>
                    </div>

                    <div class="p-5">
                        <div
                            class="flex flex-col gap-4 @xl/main:grid @xl/main:grid-cols-2 @xl/main:gap-x-5.5 @xl/main:gap-y-4.5 @4xl/main:grid-cols-3"
                        >
                            <!-- One control over two form fields: the server
                                 joins `visited_on` and `visited_time` back
                                 into one column, and each keeps its own
                                 validation rule and error. -->
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
                                <!-- Label and field name diverge on purpose:
                                     the column, enum and filter query key are
                                     still `purpose`, and renaming them to
                                     match would break every saved link. -->
                                <Label for="purpose">
                                    Nature of visit
                                    <span class="text-primary">*</span>
                                </Label>
                                <Select
                                    v-model="purposeChoice"
                                    @update:model-value="onPurposeChosen"
                                >
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

                                <!-- The menu is short by design; anything it
                                     does not cover is typed here and stored as
                                     written. -->
                                <Input
                                    v-if="purposeChoice === 'other'"
                                    id="purpose-other"
                                    ref="purposeOtherBox"
                                    v-model="purposeOther"
                                    class="mt-2.25"
                                    maxlength="120"
                                    placeholder="What brought them in?"
                                    aria-label="Describe the nature of the visit"
                                    data-test="field-purpose-other"
                                />

                                <InputError :message="form.errors.purpose" />
                            </div>

                            <div>
                                <Label for="department">
                                    Department
                                    <span class="text-primary">*</span>
                                </Label>
                                <Select
                                    v-model="departmentChoice"
                                    @update:model-value="onDepartmentChosen"
                                >
                                    <SelectTrigger
                                        id="department"
                                        class="mt-2.25 w-full"
                                        data-test="field-department"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="department in props.departments"
                                            :key="department.value"
                                            :value="department.value"
                                        >
                                            {{ department.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>

                                <Input
                                    v-if="departmentChoice === 'other'"
                                    id="department-other"
                                    ref="departmentOtherBox"
                                    v-model="departmentOther"
                                    class="mt-2.25"
                                    maxlength="120"
                                    placeholder="Which desk were they here for?"
                                    aria-label="Name the department they came to see"
                                    data-test="field-department-other"
                                />

                                <InputError :message="form.errors.department" />
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

                    <!-- ===================== Picked products ===================== -->
                    <div
                        v-if="form.products.length > 0"
                        class="overflow-x-auto border-t border-divider"
                    >
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
                                    variant="outline"
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
                <Button as-child variant="outline">
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
