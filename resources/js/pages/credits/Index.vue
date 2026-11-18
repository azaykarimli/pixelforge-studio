<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Pagination from '@/components/Pagination.vue';
import { formatBalance, formatCost, formatExact } from '@/lib/money';
import { index } from '@/routes/credits';
import type { LedgerEntry, Paginated } from '@/types';

defineProps<{
    entries: Paginated<LedgerEntry>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Credits',
                href: index(),
            },
        ],
    },
});

const page = usePage();
const credits = computed(() => page.props.credits);

function when(timestamp: string): string {
    return new Date(timestamp).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

/** The statement shows exact amounts, signed: "+$10.00", "-$0.0723125". */
function signed(entry: LedgerEntry): string {
    return entry.type === 'purchase'
        ? `+${formatExact(entry.amount)}`
        : formatExact(entry.amount);
}
</script>

<template>
    <Head title="Credits" />

    <div class="flex flex-col gap-6 p-4">
        <div v-if="credits" class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border bg-card p-4 shadow-xs">
                <p class="text-sm text-muted-foreground">Available</p>
                <p
                    class="mt-1 text-2xl font-semibold tabular-nums"
                    :title="`${formatExact(credits.available)} exactly`"
                    data-test="credit-available"
                >
                    {{ formatBalance(credits.available) }}
                </p>
                <p class="mt-2 text-xs text-muted-foreground">
                    What you can spend on new images right now.
                </p>
            </div>

            <div class="rounded-xl border bg-card p-4 shadow-xs">
                <p class="text-sm text-muted-foreground">Set aside</p>
                <p
                    class="mt-1 text-2xl font-semibold tabular-nums"
                    :title="`${formatExact(credits.reserved)} exactly`"
                    data-test="credit-reserved"
                >
                    {{ formatCost(credits.reserved) }}
                </p>
                <p class="mt-2 text-xs text-muted-foreground">
                    Held for images in progress: the most each could cost. It is
                    released when they finish, and you are charged only the
                    actual cost.
                </p>
            </div>

            <div class="rounded-xl border bg-card p-4 shadow-xs">
                <p class="text-sm text-muted-foreground">Balance</p>
                <p
                    class="mt-1 text-2xl font-semibold tabular-nums"
                    :title="`${formatExact(credits.balance)} exactly`"
                    data-test="credit-balance"
                >
                    {{ formatBalance(credits.balance) }}
                </p>
                <p class="mt-2 text-xs text-muted-foreground">
                    Everything you've bought, minus everything you've been
                    charged.
                </p>
            </div>
        </div>

        <section aria-labelledby="history-heading">
            <h2 id="history-heading" class="mb-4 text-base font-medium">
                History
            </h2>

            <div
                v-if="entries.data.length === 0"
                class="rounded-xl border border-dashed p-12 text-center text-sm text-muted-foreground"
            >
                Nothing here yet. Buy some credits to start making images.
            </div>

            <div v-else class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-normal">When</th>
                            <th class="px-4 py-3 font-normal">What</th>
                            <th class="px-4 py-3 text-right font-normal">
                                Amount
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="entry in entries.data"
                            :key="entry.id"
                            class="border-t"
                        >
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ when(entry.created_at) }}
                            </td>
                            <td class="w-full max-w-0 truncate px-4 py-3">
                                <template v-if="entry.type === 'purchase'">
                                    Credits bought
                                </template>
                                <template v-else>
                                    Image
                                    <span
                                        v-if="entry.prompt"
                                        class="text-muted-foreground"
                                        :title="entry.prompt"
                                    >
                                        · {{ entry.prompt }}
                                    </span>
                                </template>
                            </td>
                            <td
                                class="px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums"
                                :class="
                                    entry.type === 'purchase'
                                        ? 'text-emerald-700 dark:text-emerald-400'
                                        : ''
                                "
                            >
                                {{ signed(entry) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination class="mt-6" :page="entries" />
        </section>
    </div>
</template>
