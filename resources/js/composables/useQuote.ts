import { useHttp } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { computed, onMounted, ref } from 'vue';
import { quote as quoteRoute } from '@/routes/generations';
import type { ImageQuality, ImageSize, Quote } from '@/types';

export type QuoteInput = {
    prompt: string;
    size: ImageSize;
    quality: ImageQuality;
    reference_images: string[];
};

/**
 * Keeps a price quote in step with the generation form.
 *
 * The quote comes from the server rather than being worked out in the
 * browser: the code that prices the form is the code that prices the
 * submission, so the number shown is the number that gets recorded.
 */
export function useQuote(input: () => QuoteInput) {
    const quote = ref<Quote | null>(null);
    const unavailable = ref(false);

    // The request always sends whatever the form holds at that moment.
    const request = useHttp<QuoteInput, Quote>(input()).transform(input);

    let latest = 0;

    async function refresh(): Promise<void> {
        const current = ++latest;

        // A newer request makes any earlier one pointless.
        request.cancel();

        try {
            const result = await request.post(quoteRoute.url());

            // Undefined means the input didn't validate (a prompt that is too
            // long, say). The form reports that itself; keep the last quote.
            if (current === latest && result) {
                quote.value = result;
                unavailable.value = false;
            }
        } catch {
            // A cancelled request has been replaced by a newer one. Anything
            // else means prices couldn't be fetched.
            if (current === latest) {
                unavailable.value = true;
            }
        }
    }

    // Ask once the form is on screen, then again whenever it changes, waiting
    // for a pause in typing rather than asking on every keystroke.
    onMounted(refresh);
    watchDebounced(input, refresh, { debounce: 300, deep: true });

    return {
        quote,
        unavailable,
        loading: computed(() => request.processing),
        refresh,
    };
}
