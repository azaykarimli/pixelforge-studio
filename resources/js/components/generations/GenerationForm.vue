<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { ImagePlus, Sparkles, X } from '@lucide/vue';
import { computed, watch } from 'vue';
import BuyCreditsDialog from '@/components/credits/BuyCreditsDialog.vue';
import CostEstimate from '@/components/generations/CostEstimate.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useQuote } from '@/composables/useQuote';
import { qualityLabels, sizeLabels } from '@/lib/generations';
import { formatBalance, formatCost } from '@/lib/money';
import { store } from '@/routes/generations';
import type { Generation, ImageQuality, ImageSize } from '@/types';

// Pixelforge's own limits. The server enforces them; these only drive the UI.
const MAX_PROMPT_LENGTH = 4000;
const MAX_REFERENCE_IMAGES = 4;

// Kept out of the template: vue-tsc 3.3 mistakes the "//" of a URL written
// straight into a component attribute for the start of a comment.
const REFERENCE_IMAGE_PLACEHOLDER = 'https://example.com/style.png';

// Each size, with the outline drawn on its tile.
const sizes: { value: ImageSize; outline: string }[] = [
    { value: '1024x1024', outline: 'h-6 w-6' },
    { value: '1536x1024', outline: 'h-5 w-7.5' },
    { value: '1024x1536', outline: 'h-7.5 w-5' },
];

const qualities: { value: ImageQuality; hint: string }[] = [
    { value: 'low', hint: 'Quick drafts' },
    { value: 'medium', hint: 'Balanced' },
    { value: 'high', hint: 'Most detail' },
];

const form = useForm({
    prompt: '',
    size: '1024x1024' as ImageSize,
    quality: 'medium' as ImageQuality,
    reference_images: [] as string[],
});

const { quote, unavailable, loading, refresh } = useQuote(() => ({
    prompt: form.prompt,
    size: form.size,
    quality: form.quality,
    reference_images: form.reference_images,
}));

// The quote also says whether the user can afford the image. Buying credits,
// or a generation finishing, changes that without the form changing.
const page = usePage();
watch(() => page.props.credits?.available, refresh);

const tooExpensive = computed(
    () => quote.value !== null && !quote.value.affordable,
);

// Counted the way the server and Pixelforge count: in Unicode characters, so
// an emoji is one, after the surrounding whitespace has been trimmed.
const promptLength = computed(() => Array.from(form.prompt.trim()).length);
const promptTooLong = computed(() => promptLength.value > MAX_PROMPT_LENGTH);

const canSubmit = computed(
    () =>
        promptLength.value > 0 &&
        !promptTooLong.value &&
        !unavailable.value &&
        !tooExpensive.value &&
        !form.processing,
);

// Errors the server reports under keys that aren't fields of the form itself:
// one per reference image, and one for not having enough credit.
const serverErrors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function referenceImageError(index: number): string | undefined {
    return serverErrors.value[`reference_images.${index}`];
}

function submit(): void {
    // Rows that were added but left empty aren't reference images.
    form.reference_images = form.reference_images.filter(
        (url) => url.trim() !== '',
    );

    form.submit(store(), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => form.reset('prompt'),
    });
}

/**
 * Load an earlier generation's settings into the form, to run it again or
 * to adjust it first. Nothing is submitted: the price is shown again and the
 * user presses Generate themselves.
 */
function reuse(generation: Generation): void {
    form.clearErrors();
    form.prompt = generation.prompt;
    form.size = generation.size;
    form.quality = generation.quality;
    form.reference_images = [...generation.reference_images];
}

defineExpose({ reuse });
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div class="grid gap-2">
            <div class="flex items-baseline justify-between">
                <Label for="prompt">Prompt</Label>
                <span
                    class="text-xs tabular-nums"
                    :class="
                        promptTooLong
                            ? 'font-medium text-red-600 dark:text-red-500'
                            : 'text-muted-foreground'
                    "
                >
                    {{ promptLength.toLocaleString('en-US') }} /
                    {{ MAX_PROMPT_LENGTH.toLocaleString('en-US') }}
                </span>
            </div>
            <Textarea
                id="prompt"
                v-model="form.prompt"
                rows="5"
                placeholder="A lighthouse on a cliff at dawn, oil painting"
                :aria-invalid="promptTooLong || !!form.errors.prompt"
            />
            <InputError
                :message="
                    promptTooLong
                        ? 'The prompt is too long. Pixelforge accepts up to 4,000 characters.'
                        : form.errors.prompt
                "
            />
        </div>

        <fieldset>
            <legend class="mb-2 text-sm font-medium">Size</legend>
            <div class="grid grid-cols-3 gap-2">
                <label
                    v-for="size in sizes"
                    :key="size.value"
                    class="flex cursor-pointer flex-col items-center gap-1 rounded-lg border p-3 text-center transition-colors hover:bg-accent has-checked:border-primary has-checked:bg-accent has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                >
                    <input
                        v-model="form.size"
                        type="radio"
                        name="size"
                        :value="size.value"
                        class="sr-only"
                    />
                    <span class="flex h-8 items-center">
                        <span
                            class="block rounded-sm border-2 border-current"
                            :class="size.outline"
                        />
                    </span>
                    <span class="text-sm font-medium">{{
                        sizeLabels[size.value]
                    }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        size.value.replace('x', ' × ')
                    }}</span>
                </label>
            </div>
            <InputError class="mt-2" :message="form.errors.size" />
        </fieldset>

        <fieldset>
            <legend class="mb-2 text-sm font-medium">Quality</legend>
            <div class="grid grid-cols-3 gap-2">
                <label
                    v-for="quality in qualities"
                    :key="quality.value"
                    class="flex cursor-pointer flex-col items-center gap-1 rounded-lg border p-3 text-center transition-colors hover:bg-accent has-checked:border-primary has-checked:bg-accent has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                >
                    <input
                        v-model="form.quality"
                        type="radio"
                        name="quality"
                        :value="quality.value"
                        class="sr-only"
                    />
                    <span class="text-sm font-medium">{{
                        qualityLabels[quality.value]
                    }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        quality.hint
                    }}</span>
                </label>
            </div>
            <InputError class="mt-2" :message="form.errors.quality" />
        </fieldset>

        <fieldset class="grid gap-2">
            <legend class="text-sm font-medium">
                Reference images
                <span class="font-normal text-muted-foreground"
                    >(optional)</span
                >
            </legend>
            <p class="text-xs text-muted-foreground">
                Links to up to {{ MAX_REFERENCE_IMAGES }} images that guide the
                style. Each one adds to the cost.
            </p>

            <div
                v-for="(_, index) in form.reference_images"
                :key="index"
                class="grid gap-1"
            >
                <div class="flex gap-2">
                    <Input
                        v-model="form.reference_images[index]"
                        type="url"
                        inputmode="url"
                        :placeholder="REFERENCE_IMAGE_PLACEHOLDER"
                        :aria-label="`Reference image ${index + 1}`"
                        :aria-invalid="!!referenceImageError(index)"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Remove reference image ${index + 1}`"
                        @click="form.reference_images.splice(index, 1)"
                    >
                        <X />
                    </Button>
                </div>
                <InputError :message="referenceImageError(index)" />
            </div>

            <Button
                v-if="form.reference_images.length < MAX_REFERENCE_IMAGES"
                type="button"
                variant="outline"
                size="sm"
                class="w-fit"
                @click="form.reference_images.push('')"
            >
                <ImagePlus />
                Add a reference image
            </Button>
            <InputError :message="form.errors.reference_images" />
        </fieldset>

        <CostEstimate
            :quote="quote"
            :loading="loading"
            :unavailable="unavailable"
        />

        <div
            v-if="quote && tooExpensive"
            class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-950/40 dark:text-amber-100"
            data-test="insufficient-credit"
        >
            <p class="font-medium">
                You don't have enough credit for this image
            </p>
            <p class="mt-1 text-xs">
                It could cost up to {{ formatCost(quote.max) }}, and you have
                {{ formatBalance(quote.available) }} available. The most an
                image could cost is set aside until it's done.
            </p>
            <BuyCreditsDialog>
                <Button type="button" size="sm" class="mt-3">
                    Buy credits
                </Button>
            </BuyCreditsDialog>
        </div>
        <InputError :message="serverErrors.credits" />

        <Button
            type="submit"
            size="lg"
            class="w-full"
            :disabled="!canSubmit"
            data-test="generate-button"
        >
            <Spinner v-if="form.processing" />
            <Sparkles v-else />
            Generate
        </Button>
    </form>
</template>
