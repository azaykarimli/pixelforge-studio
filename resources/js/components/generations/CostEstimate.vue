<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Skeleton } from '@/components/ui/skeleton';
import { costLineLabels } from '@/lib/generations';
import { formatCost, formatExact } from '@/lib/money';
import type { CostLineKey, Estimate, Quote } from '@/types';

const props = defineProps<{
    quote: Quote | null;
    loading: boolean;
    unavailable: boolean;
}>();

/** "1,100" when the figure is certain, "200 – 2,000" when it isn't. */
function spread<T>(
    estimate: Estimate<T>,
    format: (value: T) => string,
): string {
    return estimate.min === estimate.max
        ? format(estimate.min)
        : `${format(estimate.min)} – ${format(estimate.max)}`;
}

const rows = computed(() => {
    const lines = props.quote?.lines;

    if (!lines) {
        return [];
    }

    return (Object.keys(costLineLabels) as CostLineKey[])
        .filter((key) => lines[key].tokens.max > 0)
        .map((key) => ({
            key,
            label: costLineLabels[key],
            tokens: spread(lines[key].tokens, (count) =>
                count.toLocaleString('en-US'),
            ),
            charge: spread(lines[key].charge, formatCost),
            exactCharge: spread(lines[key].charge, formatExact),
        }));
});
</script>

<template>
    <div class="rounded-lg border bg-muted/40 p-4" aria-live="polite">
        <p v-if="unavailable" class="text-sm text-red-600 dark:text-red-500">
            We can't get current prices from Pixelforge, so we can't tell you
            what this would cost. Please try again in a moment.
        </p>

        <template v-else-if="quote">
            <div class="flex items-baseline justify-between gap-4">
                <span class="text-sm font-medium">Estimated cost</span>
                <span
                    class="text-2xl font-semibold tabular-nums transition-opacity"
                    :class="{ 'opacity-50': loading }"
                    :title="`${formatExact(quote.estimated)} exactly`"
                    data-test="estimated-cost"
                >
                    {{ formatCost(quote.estimated) }}
                </span>
            </div>

            <p class="mt-2 text-xs text-muted-foreground">
                It will come to between
                <span class="font-medium text-foreground tabular-nums">{{
                    formatCost(quote.min)
                }}</span>
                and
                <span class="font-medium text-foreground tabular-nums">{{
                    formatCost(quote.max)
                }}</span
                >. How much the model reasons before drawing is only known once
                the image is done. You pay the actual cost, and nothing if the
                generation fails.
            </p>

            <Collapsible>
                <CollapsibleTrigger
                    class="group mt-3 flex items-center gap-1 text-xs font-medium underline-offset-4 hover:underline"
                >
                    How this adds up
                    <ChevronDown
                        class="size-3 transition-transform group-data-[state=open]:rotate-180"
                    />
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <table class="mt-2 w-full text-xs">
                        <thead class="text-muted-foreground">
                            <tr>
                                <th class="py-1 text-left font-normal">
                                    Charged for
                                </th>
                                <th class="py-1 text-right font-normal">
                                    Tokens
                                </th>
                                <th class="py-1 text-right font-normal">
                                    Cost
                                </th>
                            </tr>
                        </thead>
                        <tbody class="tabular-nums">
                            <tr
                                v-for="row in rows"
                                :key="row.key"
                                class="border-t"
                            >
                                <td class="py-1">{{ row.label }}</td>
                                <td class="py-1 text-right whitespace-nowrap">
                                    {{ row.tokens }}
                                </td>
                                <td
                                    class="py-1 text-right whitespace-nowrap"
                                    :title="`${row.exactCharge} exactly`"
                                >
                                    {{ row.charge }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CollapsibleContent>
            </Collapsible>
        </template>

        <div v-else class="space-y-2">
            <Skeleton class="h-7 w-full" />
            <Skeleton class="h-8 w-full" />
        </div>
    </div>
</template>
