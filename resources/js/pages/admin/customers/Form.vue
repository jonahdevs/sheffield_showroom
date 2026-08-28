<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Building2, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import DatePicker from '@/components/DatePicker.vue';
import InputError from '@/components/InputError.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import { index, store, update } from '@/routes/admin/customers';

const props = defineProps<{
    customer: App.Data.CustomerFormData | null;
    types: { value: string; label: string }[];
    default_country: string;
}>();

type CustomerType = 'individual' | 'company';

const form = useForm({
    type: (props.customer?.type ?? 'individual') as CustomerType,
    name: props.customer?.name ?? '',
    date_of_birth: props.customer?.date_of_birth ?? '',
    occupation: props.customer?.occupation ?? '',
    company_name: props.customer?.company_name ?? '',
    industry: props.customer?.industry ?? '',
    contact_person: props.customer?.contact_person ?? '',
    contact_person_position: props.customer?.contact_person_position ?? '',
    phone: props.customer?.phone ?? '',
    alternative_phone: props.customer?.alternative_phone ?? '',
    email: props.customer?.email ?? '',
    address_line_1: props.customer?.address_line_1 ?? '',
    address_line_2: props.customer?.address_line_2 ?? '',
    city: props.customer?.city ?? '',
    state: props.customer?.state ?? '',
    postal_code: props.customer?.postal_code ?? '',
    country: props.customer?.country ?? props.default_country,
    notes: props.customer?.notes ?? '',
});

const isCompany = computed(() => form.type === 'company');

const heading = computed(() =>
    props.customer ? props.customer.display_name : 'New customer',
);

/**
 * Switching type keeps whatever has already been typed on both halves - the
 * server drops the fields the chosen type does not use, so a mis-click costs
 * nothing and a correction costs no retyping.
 */
function chooseType(type: CustomerType) {
    form.type = type;
    form.clearErrors();
}

const canSubmit = computed(() => {
    if (form.processing || form.phone.trim() === '') {
        return false;
    }

    return isCompany.value
        ? form.company_name.trim() !== ''
        : form.name.trim() !== '';
});

function submit() {
    if (props.customer) {
        form.patch(update(props.customer.id).url);

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
    layout: (page: { customer: App.Data.CustomerFormData | null }) => ({
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Customers', href: index().url },
            { title: page.customer ? 'Edit' : 'Create' },
        ],
    }),
});
</script>

<!--
  One form for both kinds of customer. The type picker sits above the panels
  because it decides what the first one asks for; everything below it -
  contact, address, notes - is the same either way.

  The two-column rows switch on `@2xl/page`, the page's own width rather than
  the window's: behind the rail a viewport breakpoint promises room the form
  does not have.
-->
<template>
    <Head :title="heading" />

    <div class="flex flex-col gap-5">
        <div>
            <h1 class="text-2xl leading-tight">{{ heading }}</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                A phone number and a name are all that is needed. Everything
                else can be filled in as you learn it.
            </p>
        </div>

        <form class="flex flex-col gap-5" @submit.prevent="submit">
            <!--
              The type picker sits above the panels rather than inside the
              first one, because it decides what that panel asks for. Real
              radios, visually hidden behind the cards, so arrow keys move
              between them and the choice is one field rather than two buttons
              that happen to disagree.
            -->
            <fieldset>
                <legend class="sr-only">Customer type</legend>

                <div
                    class="flex flex-col gap-2.5 @2xl/page:grid @2xl/page:grid-cols-2"
                >
                    <label
                        v-for="type in props.types"
                        :key="type.value"
                        class="flex cursor-pointer items-center gap-3 rounded-xl border bg-card p-3.5 transition-colors has-focus-visible:border-ring has-focus-visible:ring-3 has-focus-visible:ring-ring/50"
                        :class="
                            form.type === type.value
                                ? 'border-primary bg-brand-50 dark:bg-brand-950'
                                : 'border-border hover:border-primary/40'
                        "
                    >
                        <input
                            type="radio"
                            name="type"
                            class="sr-only"
                            :value="type.value"
                            :checked="form.type === type.value"
                            :data-test="`type-${type.value}`"
                            @change="chooseType(type.value as CustomerType)"
                        />

                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                            :class="
                                form.type === type.value
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted text-faint'
                            "
                            aria-hidden="true"
                        >
                            <Building2
                                v-if="type.value === 'company'"
                                class="size-4.5"
                            />
                            <UserRound v-else class="size-4.5" />
                        </span>

                        <span class="min-w-0">
                            <span class="block text-xs font-bold">
                                {{ type.label }}
                            </span>
                            <span class="mt-0.5 block text-xs text-faint">
                                {{
                                    type.value === 'company'
                                        ? 'An organisation, reached through a contact person.'
                                        : 'A person buying in their own name.'
                                }}
                            </span>
                        </span>
                    </label>
                </div>

                <InputError :message="form.errors.type" />
            </fieldset>

            <Card as="section" class="gap-0 p-0">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Basic information
                    </h2>
                </div>

                <!-- Individual -->
                <div
                    v-if="!isCompany"
                    class="p-5"
                    data-test="individual-fields"
                >
                    <div>
                        <Label for="name"
                            >Full name
                            <span class="text-primary">*</span></Label
                        >
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

                    <div
                        class="mt-5 flex flex-col gap-4 @2xl/page:grid @2xl/page:grid-cols-2 @2xl/page:gap-x-5.5 @2xl/page:gap-y-4.5"
                    >
                        <div>
                            <Label for="date_of_birth">Date of birth</Label>
                            <div class="mt-2.25">
                                <DatePicker
                                    id="date_of_birth"
                                    v-model="form.date_of_birth"
                                    max="today"
                                    data-test="field-dob"
                                />
                            </div>
                            <InputError :message="form.errors.date_of_birth" />
                        </div>

                        <div>
                            <Label for="occupation">Occupation</Label>
                            <Input
                                id="occupation"
                                v-model="form.occupation"
                                class="mt-2.25"
                                placeholder="e.g. Contractor"
                                data-test="field-occupation"
                            />
                            <InputError :message="form.errors.occupation" />
                        </div>
                    </div>
                </div>

                <!-- Company -->
                <div v-else class="p-5" data-test="company-fields">
                    <div
                        class="flex flex-col gap-4 @2xl/page:grid @2xl/page:grid-cols-2 @2xl/page:gap-x-5.5 @2xl/page:gap-y-4.5"
                    >
                        <div>
                            <Label for="company_name"
                                >Company name
                                <span class="text-primary">*</span></Label
                            >
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
                            <Label for="industry">Industry</Label>
                            <Input
                                id="industry"
                                v-model="form.industry"
                                class="mt-2.25"
                                placeholder="e.g. Construction"
                                data-test="field-industry"
                            />
                            <InputError :message="form.errors.industry" />
                        </div>

                        <div>
                            <Label for="contact_person">Contact person</Label>
                            <Input
                                id="contact_person"
                                v-model="form.contact_person"
                                class="mt-2.25"
                                placeholder="Who to ask for"
                                data-test="field-contact-person"
                            />
                            <InputError :message="form.errors.contact_person" />
                        </div>

                        <div>
                            <Label for="contact_person_position"
                                >Their position</Label
                            >
                            <Input
                                id="contact_person_position"
                                v-model="form.contact_person_position"
                                class="mt-2.25"
                                placeholder="e.g. Procurement Manager"
                                data-test="field-contact-position"
                            />
                            <InputError
                                :message="form.errors.contact_person_position"
                            />
                        </div>
                    </div>
                </div>
            </Card>

            <Card as="section" class="gap-0 p-0">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Contact information
                    </h2>
                </div>

                <div class="p-5">
                    <div
                        class="flex flex-col gap-4 @2xl/page:grid @2xl/page:grid-cols-2 @2xl/page:gap-x-5.5 @2xl/page:gap-y-4.5"
                    >
                        <div>
                            <Label for="phone"
                                >Phone number
                                <span class="text-primary">*</span></Label
                            >
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
                            <Label for="alternative_phone"
                                >Alternative phone number</Label
                            >
                            <div class="mt-2.25">
                                <PhoneInput
                                    id="alternative_phone"
                                    v-model="form.alternative_phone"
                                    placeholder="Optional"
                                    data-test="field-alt-phone"
                                />
                            </div>
                            <InputError
                                :message="form.errors.alternative_phone"
                            />
                        </div>
                    </div>

                    <div class="mt-5">
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
                        class="flex flex-col gap-4 @2xl/page:grid @2xl/page:grid-cols-2 @2xl/page:gap-x-5.5 @2xl/page:gap-y-4.5"
                    >
                        <div>
                            <Label for="address_line_1">Address line 1</Label>
                            <Input
                                id="address_line_1"
                                v-model="form.address_line_1"
                                class="mt-2.25"
                                placeholder="e.g. Plot 14, Enterprise Road"
                                autocomplete="address-line1"
                                data-test="field-address-line-1"
                            />
                            <InputError :message="form.errors.address_line_1" />
                        </div>

                        <div>
                            <Label for="address_line_2">Address line 2</Label>
                            <Input
                                id="address_line_2"
                                v-model="form.address_line_2"
                                class="mt-2.25"
                                placeholder="Estate, block or building"
                                autocomplete="address-line2"
                                data-test="field-address-line-2"
                            />
                            <InputError :message="form.errors.address_line_2" />
                        </div>
                    </div>

                    <div
                        class="mt-5 flex flex-col gap-4 @2xl/page:grid @2xl/page:grid-cols-2 @2xl/page:gap-x-5.5 @2xl/page:gap-y-4.5"
                    >
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
                    </div>
                </div>
            </Card>

            <Card as="section" class="gap-0 p-0">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Notes
                    </h2>
                </div>

                <div class="p-5">
                    <Label for="notes" class="sr-only">Notes</Label>
                    <Textarea
                        id="notes"
                        v-model="form.notes"
                        rows="4"
                        placeholder="What they came in for, what they were quoted, anything worth knowing next time."
                        data-test="field-notes"
                    />
                    <InputError :message="form.errors.notes" />
                </div>
            </Card>

            <div class="flex items-center justify-end gap-3">
                <Button as-child variant="quiet">
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
