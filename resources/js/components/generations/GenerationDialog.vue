<script setup lang="ts">
import { ExternalLink, RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import GenerationImage from '@/components/generations/GenerationImage.vue';
import StatusBadge from '@/components/generations/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    costLineLabels,
    failureMessage,
    qualityLabels,
    sizeLabels,
} from '@/lib/generations';
import { formatCost, formatExact } from '@/lib/money';
import type { CostLineKey, Generation } from '@/types';

const props = defineProps<{
    // The generation to show. The dialog is open whenever there is one.
    generation: Generation | null;
}>();

const emit = defineEmits<{
    close: [];
    reuse: [generation: Generation];
}>();

// What the final charge was made up of, leaving out anything that wasn't used.
const breakdown = computed(() => {
    const lines = props.generation?.breakdown;

    if (!lines) {
        return [];
    }

    return (Object.keys(costLineLabels) as CostLineKey[])
        .filter((key) => lines[key].tokens > 0)
        .map((key) => ({ key, label: costLineLabels[key], ...lines[key] }));
});

function when(timestamp: string): string {
    return new Date(timestamp).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'medium',
    });
}
</script>

<template>
    <Dialog
        :open="generation !== null"
        @update:open="(open) => open || emit('close')"
    >
        <DialogContent
            class="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-2xl"
        >
            <template v-if="generation">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        Image details
                        <StatusBadge :status="generation.status" />
                    </DialogTitle>
                    <DialogDescription class="whitespace-pre-line">
                        {{ generation.prompt }}
                    </DialogDescription>
                </DialogHeader>

                <GenerationImage
                    :generation="generation"
                    fit="contain"
                    class="h-72 rounded-lg sm:h-96"
                />

                <p
                    v-if="generation.status === 'failed'"
                    class="text-sm text-red-600 dark:text-red-500"
                >
                    {{ failureMessage(generation) }} You weren't charged.
                </p>

                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div>
                        <dt class="text-muted-foreground">Size</dt>
                        <dd>
                            {{ sizeLabels[generation.size] }},
                            {{ generation.size.replace('x', ' × ') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Quality</dt>
                        <dd>{{ qualityLabels[generation.quality] }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Requested</dt>
                        <dd>{{ when(generation.created_at) }}</dd>
                    </div>
                    <div v-if="generation.completed_at">
                        <dt class="text-muted-foreground">Finished</dt>
                        <dd>{{ when(generation.completed_at) }}</dd>
                    </div>
                    <div v-if="generation.reference_images.length > 0">
                        <dt class="text-muted-foreground">Reference images</dt>
                        <dd>{{ generation.reference_images.length }}</dd>
                    </div>
                </dl>

                <div class="rounded-lg border bg-muted/40 p-4 text-sm">
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-3">
                        <div>
                            <dt class="text-muted-foreground">
                                Estimated cost
                            </dt>
                            <dd class="tabular-nums">
                                {{ formatExact(generation.estimated_charge) }}
                                <span class="text-muted-foreground">
                                    (at most
                                    {{ formatExact(generation.max_charge) }})
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Actual cost</dt>
                            <dd
                                v-if="generation.charge !== null"
                                class="font-semibold tabular-nums"
                            >
                                {{ formatExact(generation.charge) }}
                            </dd>
                            <dd v-else-if="generation.status === 'failed'">
                                Not charged
                            </dd>
                            <dd v-else class="text-muted-foreground">
                                Known when the image is done
                            </dd>
                        </div>
                    </dl>

                    <table
                        v-if="breakdown.length > 0"
                        class="mt-4 w-full text-xs"
                    >
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
                                v-for="line in breakdown"
                                :key="line.key"
                                class="border-t"
                            >
                                <td class="py-1">{{ line.label }}</td>
                                <td class="py-1 text-right">
                                    {{ line.tokens.toLocaleString('en-US') }}
                                </td>
                                <td class="py-1 text-right">
                                    {{ formatExact(line.charge) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p
                        v-if="generation.charge !== null"
                        class="mt-3 text-xs text-muted-foreground"
                    >
                        Shown elsewhere as
                        {{ formatCost(generation.charge) }}: costs are rounded
                        up to four decimals on screen, but charged exactly.
                    </p>
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        v-if="generation.image_url"
                        variant="ghost"
                        as-child
                    >
                        <a
                            :href="generation.image_url"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <ExternalLink />
                            Open full size
                        </a>
                    </Button>
                    <Button
                        variant="outline"
                        @click="emit('reuse', generation)"
                    >
                        <RotateCcw />
                        Use these settings again
                    </Button>
                </DialogFooter>
            </template>
        </DialogContent>
    </Dialog>
</template>
