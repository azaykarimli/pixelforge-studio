<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/credits';

const page = usePage();
const packs = computed(() => page.props.credits?.packs ?? []);

const open = ref(false);

// The pack being bought, so the buttons can't be pressed twice.
const buying = ref<number | null>(null);

function buy(dollars: number): void {
    router.post(
        store.url(),
        { dollars },
        {
            // Stay exactly where the user was: if they came here from a
            // half-written prompt, it must still be there afterwards.
            preserveScroll: true,
            preserveState: true,
            onStart: () => (buying.value = dollars),
            onFinish: () => (buying.value = null),
            onSuccess: () => (open.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot>
                <Button size="sm" data-test="buy-credits-button">
                    Buy credits
                </Button>
            </slot>
        </DialogTrigger>
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Buy credits</DialogTitle>
                <DialogDescription>
                    Credits are US dollars, and images are paid for from them.
                    This is a demo, so no payment is taken: the credit is simply
                    added to your account.
                </DialogDescription>
            </DialogHeader>

            <div class="grid grid-cols-3 gap-2">
                <Button
                    v-for="dollars in packs"
                    :key="dollars"
                    variant="outline"
                    class="h-16 text-lg font-semibold"
                    :disabled="buying !== null"
                    :data-test="`buy-${dollars}`"
                    @click="buy(dollars)"
                >
                    <Spinner v-if="buying === dollars" />
                    <template v-else>${{ dollars }}</template>
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
