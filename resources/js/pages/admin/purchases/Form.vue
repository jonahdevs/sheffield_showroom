<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import OptionCombobox from '@/components/OptionCombobox.vue';
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
import { index, store, update } from '@/routes/admin/purchases';

/**
 * A purchase as `PurchaseController::edit` sends it.
 *
 * Spelled out here rather than imported from `App.Data`, because the
 * controller assembles this shape inline rather than through a Data object, so
 * nothing is generated for it. `visit_id` is carried in the type even though
 * the form has no control for it - see the note on the form below.
 */
interface PurchaseForm {
    id: number;
    customer_id: number;
    visit_id: number | null;
    reference: string | null;
    amount: string;
    status: App.Enums.PurchaseStatus;
    /** Already `Y-m-d\TH:i:s`, which is what a datetime-local input wants. */
    purchased_at: string;
}

const props = defineProps<{
    purchase: PurchaseForm | null;
    statuses: { value: string; label: string }[];
    customers: App.Data.OptionData[];
}>();

/*
  `visit_id` is deliberately absent from the payload rather than sent as null.

  A purchase can be filed against the call it happened on, but that tie is made
  where the visit is - this screen has no visit picker and no business guessing
  one. Leaving the key out entirely means `validated()` never carries it, so an
  edit keeps whatever visit the sale was already attached to. Sending null
  instead would quietly cut a sale loose from its visit every time somebody
  corrected a typo in the reference.
*/
const form = useForm({
    customer_id: props.purchase?.customer_id ?? (null as number | null),
    reference: props.purchase?.reference ?? '',
    amount: props.purchase?.amount ?? '',
    status: props.purchase?.status ?? 'completed',
    purchased_at: props.purchase?.purchased_at ?? nowForInput(),
});

/**
 * The current local moment in the shape a datetime-local input reads.
 *
 * `toISOString()` would be UTC, which east of Greenwich hands back a time
 * three hours ago and west of it one in the future - and "in the future" is
 * exactly what the server refuses. Subtracting the offset first keeps the
 * value in the clock the person at the counter is looking at.
 */
function nowForInput(): string {
    const now = new Date();
    const local = new Date(now.getTime() - now.getTimezoneOffset() * 60_000);

    return local.toISOString().slice(0, 16);
}

/**
 * A ceiling on the picker, because `purchased_at` is refused if it is in the
 * future. Read once at setup rather than kept ticking: a form open for an hour
 * that silently changes its own limits is stranger than one whose ceiling is
 * an hour stale, and the server has the real say either way.
 */
const latestMoment = nowForInput();

const heading = computed(() => {
    if (props.purchase === null) {
        return 'New purchase';
    }

    return props.purchase.reference
        ? `Purchase ${props.purchase.reference}`
        : 'Edit purchase';
});

/**
 * Grouping separators and stray spaces are stripped, and nothing else.
 *
 * A figure typed as "1,500.00" is what somebody reading a receipt writes, and
 * `decimal:0,2` would refuse it over a comma rather than over the amount. A
 * third decimal place is left exactly as typed on purpose: the server refuses
 * those rather than rounding them, because a purchase landing the wrong side
 * of a campaign threshold by a rounding error is a customer told they did not
 * qualify when they did, and silently correcting it here would hide the typo
 * instead of showing it.
 */
function tidyAmount() {
    form.amount = form.amount.replace(/[\s,]/g, '');
}

const canSubmit = computed(
    () =>
        !form.processing &&
        form.customer_id !== null &&
        form.amount.trim() !== '' &&
        form.purchased_at !== '',
);

function submit() {
    if (props.purchase) {
        form.patch(update(props.purchase.id).url);

        return;
    }

    form.post(store().url);
}

/*
 * A layout callback rather than a static object: the same component serves
 * both routes, and only the page's own props know which one this is. Returning
 * props alone is enough - the default layout is already configured in app.ts.
 */
defineOptions({
    layout: (page: { purchase: PurchaseForm | null }) => ({
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Purchases', href: index().url },
            { title: page.purchase ? 'Edit' : 'Create' },
        ],
    }),
});
</script>

<!--
  One form for recording a sale, and the same one for correcting it.

  It is a short form because a purchase here is an eligibility record rather
  than a ledger: who bought, for how much, when, and whether it counts yet.
  Line items live on the receipt, not in this table.

  The customer leads, and the amount sits directly under the status it depends
  on - those two together are what a reward campaign reads, so they are the two
  worth checking before saving. The rows go two across on `@2xl/page` and three
  on `@4xl/page`: the page's own width rather than the window's, because behind
  the rail a viewport breakpoint promises room the form does not have.
-->
<template>
    <Head :title="heading" />

    <div class="flex flex-col gap-5">
        <div>
            <h1 class="text-2xl leading-tight">{{ heading }}</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                The amount and the status decide whether this sale earns a
                shuffle, so they are worth reading twice.
            </p>
        </div>

        <form class="flex flex-col gap-5" @submit.prevent="submit">
            <Card as="section" class="gap-0 p-0">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Purchase details
                    </h2>
                </div>

                <div class="p-5">
                    <div
                        class="flex flex-col gap-4 @2xl/page:grid @2xl/page:grid-cols-2 @2xl/page:gap-x-5.5 @2xl/page:gap-y-4.5 @4xl/page:grid-cols-3"
                    >
                        <!-- Leads the form, and takes the full first row on a
                             wide page: a name and a phone number need the
                             width, and choosing the wrong person here files
                             the sale - and any reward won on it - against a
                             stranger. -->
                        <div class="@2xl/page:col-span-2 @4xl/page:col-span-1">
                            <Label for="customer_id">
                                Customer <span class="text-primary">*</span>
                            </Label>
                            <div class="mt-2.25">
                                <OptionCombobox
                                    id="customer_id"
                                    v-model="form.customer_id"
                                    :options="props.customers"
                                    placeholder="Choose a customer"
                                    search-placeholder="Name, company or phone number"
                                    empty-text="No customer matches that."
                                    data-test="field-customer"
                                />
                            </div>
                            <InputError :message="form.errors.customer_id" />
                        </div>

                        <div>
                            <Label for="reference">Reference</Label>
                            <!-- Monospaced, because this is copied off a
                                 receipt character by character and read back
                                 the same way. -->
                            <Input
                                id="reference"
                                v-model="form.reference"
                                class="mt-2.25 font-mono"
                                placeholder="Receipt or invoice number"
                                data-test="field-reference"
                            />
                            <InputError :message="form.errors.reference" />
                        </div>

                        <!-- Text with a numeric keypad rather than
                             `type="number"`: a number input hands back
                             whatever its own locale made of the keystrokes,
                             will happily produce "1e3", and lets a scroll
                             wheel over a focused field change an amount
                             somebody has already typed. The value has to reach
                             the server as plain decimal digits, so it is held
                             as the string it will be posted as. -->
                        <div>
                            <Label for="amount">
                                Amount <span class="text-primary">*</span>
                            </Label>
                            <div class="relative mt-2.25">
                                <span
                                    class="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-xs text-faint"
                                >
                                    KSh
                                </span>
                                <Input
                                    id="amount"
                                    v-model="form.amount"
                                    inputmode="decimal"
                                    class="pl-12 text-right tabular-nums"
                                    placeholder="0.00"
                                    data-test="field-amount"
                                    @blur="tidyAmount"
                                />
                            </div>
                            <p class="mt-1.5 text-xs text-faint">
                                Two decimal places at most.
                            </p>
                            <InputError :message="form.errors.amount" />
                        </div>

                        <div>
                            <Label for="status">
                                Status <span class="text-primary">*</span>
                            </Label>
                            <Select v-model="form.status">
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
                                Only a completed purchase earns a shuffle.
                            </p>
                            <InputError :message="form.errors.status" />
                        </div>

                        <!-- One control, because it is one answer: a sale
                             happened at a moment, not on a date and separately
                             at a time. `max` is the browser saying what the
                             server would say anyway - a sale that has not
                             happened yet cannot have earned anything, and a
                             mistyped year is the usual way one arrives. -->
                        <div>
                            <Label for="purchased_at">
                                Purchased <span class="text-primary">*</span>
                            </Label>
                            <Input
                                id="purchased_at"
                                v-model="form.purchased_at"
                                type="datetime-local"
                                :max="latestMoment"
                                class="mt-2.25"
                                data-test="field-purchased-at"
                            />
                            <InputError :message="form.errors.purchased_at" />
                        </div>
                    </div>
                </div>
            </Card>

            <div class="flex items-center justify-end gap-3">
                <Button as-child variant="quiet">
                    <Link :href="index().url">Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    :disabled="!canSubmit"
                    data-test="save-purchase"
                >
                    {{ props.purchase ? 'Save changes' : 'Record purchase' }}
                </Button>
            </div>
        </form>
    </div>
</template>
