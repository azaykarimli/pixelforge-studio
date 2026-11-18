<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Check, CircleHelp, TriangleAlert } from '@lucide/vue';
import { computed, reactive } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatFull } from '@/lib/money';
import { spend } from '@/routes/admin';
import type { SpendReport } from '@/types';

const props = defineProps<{
    from: string;
    to: string;
    report: SpendReport;
    // Whether Pixelforge's own usage report could be fetched to compare with.
    compared: boolean;
    // Generations that haven't finished yet, across all users.
    inFlight: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Spend report',
                href: spend(),
            },
        ],
    },
});

const page = usePage();
const range = reactive({ from: props.from, to: props.to });

function apply(): void {
    router.get(spend.url(), range, {
        preserveState: true,
        preserveScroll: true,
    });
}

// Each day's provider cost is set against what Pixelforge's own usage report
// says it billed us that day.
const differing = computed(() =>
    props.report.days.filter((day) => day.matches === false),
);
</script>

<template>
    <Head title="Spend report" />

    <div class="flex flex-col gap-6 p-4">
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <div class="grid gap-2">
                <Label for="from">From</Label>
                <Input id="from" v-model="range.from" type="date" />
            </div>
            <div class="grid gap-2">
                <Label for="to">To</Label>
                <Input id="to" v-model="range.to" type="date" />
            </div>
            <Button type="submit" variant="outline">Show</Button>
            <p class="basis-full text-xs text-muted-foreground">
                Days are UTC days, and an image counts on the day it was
                completed. That is how Pixelforge bills, so the two can be
                compared day by day.
            </p>
            <InputError
                class="basis-full"
                :message="page.props.errors.from ?? page.props.errors.to"
            />
        </form>

        <!-- Do our provider costs agree with Pixelforge's own record? -->
        <div
            v-if="!compared"
            class="flex items-start gap-3 rounded-xl border p-4 text-sm"
            data-test="reconciliation"
            data-state="unknown"
        >
            <CircleHelp class="mt-0.5 size-4 shrink-0" />
            <p>
                Pixelforge's usage report couldn't be fetched just now, so
                provider costs below are ours alone and haven't been compared.
                Reload to try again.
            </p>
        </div>
        <div
            v-else-if="differing.length > 0"
            class="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-950/40 dark:text-amber-100"
            data-test="reconciliation"
            data-state="differs"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            <p>
                Our provider cost differs from Pixelforge's usage report on
                {{ differing.length }}
                {{ differing.length === 1 ? 'day' : 'days' }}.
                <template v-if="inFlight > 0">
                    {{ inFlight }}
                    {{ inFlight === 1 ? 'image is' : 'images are' }} still in
                    progress. Pixelforge bills an image the moment it finishes,
                    and we record it moments later, so this may clear by itself.
                </template>
            </p>
        </div>
        <div
            v-else
            class="flex items-start gap-3 rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-500/40 dark:bg-emerald-950/40 dark:text-emerald-100"
            data-test="reconciliation"
            data-state="matches"
        >
            <Check class="mt-0.5 size-4 shrink-0" />
            <p>
                Our provider costs match Pixelforge's usage report exactly, to
                the last decimal, for every day in this range.
            </p>
        </div>

        <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border bg-card p-4 shadow-xs">
                <dt class="text-sm text-muted-foreground">Credit spend</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ formatFull(report.totals.spend) }}
                </dd>
                <dd class="mt-2 text-xs text-muted-foreground">
                    What users were charged.
                </dd>
            </div>
            <div class="rounded-xl border bg-card p-4 shadow-xs">
                <dt class="text-sm text-muted-foreground">Provider cost</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ formatFull(report.totals.provider_cost) }}
                </dd>
                <dd class="mt-2 text-xs text-muted-foreground">
                    What Pixelforge charged us.
                </dd>
            </div>
            <div class="rounded-xl border bg-card p-4 shadow-xs">
                <dt class="text-sm text-muted-foreground">Profit</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ formatFull(report.totals.profit) }}
                </dd>
                <dd class="mt-2 text-xs text-muted-foreground">
                    Spend minus provider cost.
                </dd>
            </div>
            <div class="rounded-xl border bg-card p-4 shadow-xs">
                <dt class="text-sm text-muted-foreground">Images</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ report.totals.generations.toLocaleString('en-US') }}
                </dd>
                <dd class="mt-2 text-xs text-muted-foreground">
                    Succeeded. Failed ones cost nothing.
                </dd>
            </div>
        </dl>

        <section aria-labelledby="by-day-heading">
            <h2 id="by-day-heading" class="mb-4 text-base font-medium">
                By day and user
            </h2>

            <p
                v-if="report.days.length === 0"
                class="rounded-xl border border-dashed p-12 text-center text-sm text-muted-foreground"
            >
                No images were completed in this range.
            </p>

            <div v-else class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full text-sm whitespace-nowrap">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-normal">Day / user</th>
                            <th class="px-4 py-3 text-right font-normal">
                                Images
                            </th>
                            <th class="px-4 py-3 text-right font-normal">
                                Credit spend
                            </th>
                            <th class="px-4 py-3 text-right font-normal">
                                Provider cost
                            </th>
                            <th class="px-4 py-3 text-right font-normal">
                                Profit
                            </th>
                            <th class="px-4 py-3 text-right font-normal">
                                Pixelforge reports
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        v-for="day in report.days"
                        :key="day.date"
                        class="tabular-nums"
                        :data-day="day.date"
                    >
                        <tr class="border-t bg-muted/40 font-medium">
                            <td class="px-4 py-3">{{ day.date }}</td>
                            <td class="px-4 py-3 text-right">
                                {{ day.generations }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                {{ formatFull(day.spend) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                {{ formatFull(day.provider_cost) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                {{ formatFull(day.profit) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <span
                                    v-if="day.pixelforge_cost === null"
                                    class="font-normal text-muted-foreground"
                                >
                                    Not available
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1.5"
                                    :class="
                                        day.matches
                                            ? 'text-emerald-700 dark:text-emerald-400'
                                            : 'text-amber-700 dark:text-amber-400'
                                    "
                                >
                                    {{ formatFull(day.pixelforge_cost) }}
                                    <Check v-if="day.matches" class="size-4" />
                                    <TriangleAlert v-else class="size-4" />
                                    <span class="sr-only">{{
                                        day.matches ? 'Matches' : 'Differs'
                                    }}</span>
                                </span>
                            </td>
                        </tr>
                        <tr
                            v-for="line in day.users"
                            :key="line.user?.id ?? 'deleted'"
                            class="border-t"
                        >
                            <td class="py-2 pr-4 pl-8">
                                <template v-if="line.user">
                                    {{ line.user.name }}
                                    <span class="text-muted-foreground">
                                        · {{ line.user.email }}
                                    </span>
                                </template>
                                <span v-else class="text-muted-foreground">
                                    Deleted account
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ line.generations }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ formatFull(line.spend) }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ formatFull(line.provider_cost) }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ formatFull(line.profit) }}
                            </td>
                            <td />
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            v-if="report.users.length > 0"
            aria-labelledby="by-user-heading"
        >
            <h2 id="by-user-heading" class="mb-4 text-base font-medium">
                By user, over the whole range
            </h2>

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full text-sm whitespace-nowrap">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-normal">User</th>
                            <th class="px-4 py-3 text-right font-normal">
                                Images
                            </th>
                            <th class="px-4 py-3 text-right font-normal">
                                Credit spend
                            </th>
                            <th class="px-4 py-3 text-right font-normal">
                                Provider cost
                            </th>
                            <th class="px-4 py-3 text-right font-normal">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody class="tabular-nums">
                        <tr
                            v-for="line in report.users"
                            :key="line.user?.id ?? 'deleted'"
                            class="border-t"
                        >
                            <td class="px-4 py-3">
                                <template v-if="line.user">
                                    {{ line.user.name }}
                                    <span class="text-muted-foreground">
                                        · {{ line.user.email }}
                                    </span>
                                </template>
                                <span v-else class="text-muted-foreground">
                                    Deleted account
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                {{ line.generations }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                {{ formatFull(line.spend) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                {{ formatFull(line.provider_cost) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                {{ formatFull(line.profit) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
