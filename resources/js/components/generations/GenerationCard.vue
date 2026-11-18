<script setup lang="ts">
import { RotateCcw } from '@lucide/vue';
import { useTimeAgo } from '@vueuse/core';
import GenerationImage from '@/components/generations/GenerationImage.vue';
import StatusBadge from '@/components/generations/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { failureMessage, qualityLabels, sizeLabels } from '@/lib/generations';
import { formatCost, formatExact } from '@/lib/money';
import type { Generation } from '@/types';

const props = defineProps<{
    generation: Generation;
}>();

defineEmits<{
    open: [];
    reuse: [];
}>();

const requested = useTimeAgo(() => props.generation.created_at);
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-xl border bg-card text-card-foreground shadow-xs"
        :data-status="generation.status"
    >
        <button
            type="button"
            class="relative block w-full cursor-pointer outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
            :aria-label="`Show details for: ${generation.prompt}`"
            @click="$emit('open')"
        >
            <GenerationImage
                :generation="generation"
                class="aspect-square w-full"
            />
            <StatusBadge
                :status="generation.status"
                class="absolute top-2 left-2"
            />
        </button>

        <div class="flex flex-1 flex-col gap-3 p-3">
            <div>
                <p class="line-clamp-2 text-sm" :title="generation.prompt">
                    {{ generation.prompt }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ sizeLabels[generation.size] }} ·
                    {{ qualityLabels[generation.quality] }} quality ·
                    {{ requested }}
                </p>
            </div>

            <p
                v-if="generation.status === 'failed'"
                class="text-xs text-red-600 dark:text-red-500"
            >
                {{ failureMessage(generation) }}
            </p>

            <dl class="mt-auto grid gap-1 border-t pt-3 text-xs">
                <div class="flex justify-between gap-2">
                    <dt class="text-muted-foreground">Estimated cost</dt>
                    <dd
                        class="tabular-nums"
                        :title="`${formatExact(generation.estimated_charge)} exactly`"
                    >
                        {{ formatCost(generation.estimated_charge) }}
                    </dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-muted-foreground">Actual cost</dt>
                    <dd
                        v-if="generation.charge !== null"
                        class="font-semibold tabular-nums"
                        :title="`${formatExact(generation.charge)} exactly`"
                    >
                        {{ formatCost(generation.charge) }}
                    </dd>
                    <dd
                        v-else-if="generation.status === 'failed'"
                        class="font-medium"
                    >
                        Not charged
                    </dd>
                    <dd v-else class="text-muted-foreground">
                        Known when done
                    </dd>
                </div>
            </dl>

            <Button
                v-if="generation.status === 'failed'"
                variant="outline"
                size="sm"
                @click="$emit('reuse')"
            >
                <RotateCcw />
                Try again
            </Button>
        </div>
    </article>
</template>
