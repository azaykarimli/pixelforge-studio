<script setup lang="ts">
import { CircleAlert, Clock, ImageOff } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import type { Generation } from '@/types';

const props = withDefaults(
    defineProps<{
        generation: Generation;
        fit?: 'cover' | 'contain';
    }>(),
    {
        fit: 'cover',
    },
);

// Pixelforge serves images from signed links that stop working 24 hours
// after the generation completed. Once that time has passed, or the image
// fails to load for any other reason, show a placeholder instead.
const failedToLoad = ref(false);

watch(
    () => props.generation.image_url,
    () => (failedToLoad.value = false),
);

const expired = computed(() => {
    const expiresAt = props.generation.image_url_expires_at;

    return (
        failedToLoad.value ||
        (expiresAt !== null && new Date(expiresAt).getTime() <= Date.now())
    );
});
</script>

<template>
    <div
        class="flex items-center justify-center overflow-hidden bg-muted text-muted-foreground"
    >
        <template v-if="generation.status === 'succeeded'">
            <img
                v-if="generation.image_url && !expired"
                :src="generation.image_url"
                :alt="generation.prompt"
                loading="lazy"
                class="size-full"
                :class="fit === 'cover' ? 'object-cover' : 'object-contain'"
                @error="failedToLoad = true"
            />
            <div
                v-else
                class="flex flex-col items-center gap-2 p-4 text-center"
            >
                <ImageOff class="size-6" />
                <p class="text-xs">
                    This image has expired. Pixelforge keeps images for 24
                    hours.
                </p>
            </div>
        </template>

        <div
            v-else-if="generation.status === 'failed'"
            class="flex flex-col items-center gap-2 p-4 text-center text-red-700 dark:text-red-300"
        >
            <CircleAlert class="size-6" />
            <p class="text-xs">No image was made</p>
        </div>

        <div
            v-else-if="generation.status === 'processing'"
            class="flex size-full animate-pulse flex-col items-center justify-center gap-2 bg-blue-50 p-4 text-center text-blue-800 dark:bg-blue-950/40 dark:text-blue-200"
        >
            <Spinner class="size-6" />
            <p class="text-xs">Generating your image…</p>
        </div>

        <div v-else class="flex flex-col items-center gap-2 p-4 text-center">
            <Clock class="size-6" />
            <p class="text-xs">
                {{
                    generation.status === 'pending'
                        ? 'Waiting for a free slot…'
                        : "In Pixelforge's queue…"
                }}
            </p>
        </div>
    </div>
</template>
