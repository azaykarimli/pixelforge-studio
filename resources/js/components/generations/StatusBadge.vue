<script setup lang="ts">
import { Check, Clock, X } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { statusLabels } from '@/lib/generations';
import type { GenerationStatus } from '@/types';

defineProps<{
    status: GenerationStatus;
}>();

const styles: Record<GenerationStatus, string> = {
    pending:
        'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200',
    queued: 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200',
    processing: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    succeeded:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
};
</script>

<template>
    <Badge class="border-transparent shadow-sm" :class="styles[status]">
        <Spinner v-if="status === 'processing'" />
        <Check v-else-if="status === 'succeeded'" />
        <X v-else-if="status === 'failed'" />
        <Clock v-else />
        {{ statusLabels[status] }}
    </Badge>
</template>
