<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import DatePicker from '@/components/DatePicker.vue';
import InputError from '@/components/InputError.vue';
import OptionCombobox from '@/components/OptionCombobox.vue';
import OptionMultiCombobox from '@/components/OptionMultiCombobox.vue';
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

/** Spelled out here because `PurchaseController::edit` assembles this shape inline, so no `App.Data` type is generated for it. */
interface PurchaseForm {
    id: number;
    customer_id: number;
    visit_id: number | null;
    product_ids: number[];
    reference: string | null;
    amount: string;
    status: App.Enums.PurchaseStatus;
    /** Already `Y-m-d\TH:i:s`, so the two halves are a slice apart. */
    purchased_at: string;
}

const props = defineProps<{
    purchase: PurchaseForm | null;
    statuses: { value: string; label: string }[];
    customers: App.Data.OptionData[];
    /** The floor as it stands today - see `PurchaseController::productOptions`. */
    products: App.Data.OptionData[];
    /**
     * What this sale already names, including products since withdrawn from the
     * floor and therefore missing from `products`. Without it a withdrawn
     * product's chip has no name to draw and the next save posts the selection
     * back with a hole in it.
     */
    selected_products: App.Data.OptionData[];
}>();

/**
 * Today and the hour, in the shapes the calendar and the clock field read.
 *
 * Assembled from the local `get*` parts rather than slicing `toISOString()`,
 * which is UTC and west of Greenwich yields a future time - exactly what the
 * server refuses.
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
  `visit_id` is deliberately absent from the payload rather than sent as null:
  the tie to a visit is made elsewhere, and leaving the key out means
  `validated()` never carries it, so an edit keeps the visit the sale already
  had. Sending null would cut the sale loose on every unrelated correction.

  `product_ids` is the opposite and must not be "fixed" to match - it is always
  sent, including empty, because emptying the picker is a real correction.
  `PurchaseController::syncProducts` reads absent and empty differently.
*/
const form = useForm({
    customer_id: props.purchase?.customer_id ?? (null as number | null),
    product_ids: props.purchase?.product_ids ?? ([] as number[]),
    reference: props.purchase?.reference ?? '',
    amount: props.purchase?.amount ?? '',
    status: props.purchase?.status ?? 'completed',
    purchased_at: props.purchase?.purchased_at ?? `${now.date}T${now.time}`,
});

/*
  `DatePicker` keeps the day and the clock apart while the server takes a
  single `purchased_at`, so the halves are split here and rejoined below rather
  than on the wire - unlike a visit, whose two columns the server joins itself.

  The clock is held separately so that clearing the calendar does not throw the
  time away: an empty day empties the field, `canSubmit` holds the form, and
  picking a day again restores the hour that was typed.
*/
const purchasedDay = ref(props.purchase?.purchased_at.slice(0, 10) ?? now.date);
const purchasedTime = ref(
    props.purchase?.purchased_at.slice(11, 16) ?? now.time,
);

watchEffect(() => {
    form.purchased_at =
        purchasedDay.value === ''
            ? ''
            : `${purchasedDay.value}T${purchasedTime.value === '' ? '00:00' : purchasedTime.value}`;
});

const heading = computed(() => {
    if (props.purchase === null) {
        return 'New purchase';
    }

    return props.purchase.reference
        ? `Purchase ${props.purchase.reference}`
        : 'Edit purchase';
});

/**
 * Strips grouping separators and stray spaces, and nothing else. A third
 * decimal place is deliberately left as typed so the server rejects it rather
 * than this rounding a purchase across a campaign threshold silently.
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

/** A callback rather than a static object: the same component serves both routes. */
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
                            <Input
                                id="reference"
                                v-model="form.reference"
                                class="mt-2.25 font-mono"
                                placeholder="Receipt or invoice number"
                                data-test="field-reference"
                            />
                            <InputError :message="form.errors.reference" />
                        </div>

                        <!-- Text with a numeric keypad rather than `type="number"`: a
                             number input is locale-dependent, accepts "1e3", and lets a
                             scroll wheel silently change a typed amount. -->
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

                        <div>
                            <Label for="purchased_at">
                                Purchased <span class="text-primary">*</span>
                            </Label>
                            <div class="mt-2.25">
                                <DatePicker
                                    id="purchased_at"
                                    v-model="purchasedDay"
                                    v-model:time="purchasedTime"
                                    with-time
                                    max="today"
                                    placeholder="Pick the date of sale"
                                    data-test="field-purchased-at"
                                />
                            </div>
                            <InputError :message="form.errors.purchased_at" />
                        </div>

                        <div class="@2xl/page:col-span-2 @4xl/page:col-span-3">
                            <Label for="product_ids">What was bought</Label>
                            <div class="mt-2.25">
                                <OptionMultiCombobox
                                    id="product_ids"
                                    v-model="form.product_ids"
                                    :options="props.products"
                                    :selected="props.selected_products"
                                    placeholder="Nothing recorded"
                                    search-placeholder="Product name or SKU"
                                    empty-text="No product matches that."
                                    data-test="field-products"
                                />
                            </div>
                            <p class="mt-1.5 text-xs text-faint">
                                Most sales leave this empty. It is read only by
                                a reward paired to a product - buy the oven, win
                                the tray - and it decides which of those this
                                customer is in the running for when they
                                shuffle.
                            </p>
                            <InputError :message="form.errors.product_ids" />
                        </div>
                    </div>
                </div>
            </Card>

            <div class="flex items-center justify-end gap-3">
                <Button as-child variant="outline">
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
