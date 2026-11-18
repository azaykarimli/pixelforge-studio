<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Images } from '@lucide/vue';
import { computed, onMounted, ref, useTemplateRef, watch } from 'vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import GenerationDialog from '@/components/generations/GenerationDialog.vue';
import GenerationForm from '@/components/generations/GenerationForm.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { isFinished } from '@/lib/generations';
import { index } from '@/routes/generations';
import type { Generation, Paginated } from '@/types';

const props = defineProps<{
    generations: Paginated<Generation>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Studio',
                href: index(),
            },
        ],
    },
});

const form = useTemplateRef<InstanceType<typeof GenerationForm>>('form');
const formSection = useTemplateRef<HTMLElement>('formSection');

// The generation whose details are open. It is looked up by id each time,
// so the dialog keeps up as statuses change underneath it.
const openId = ref<string | null>(null);

const openGeneration = computed(
    () =>
        props.generations.data.find(
            (generation) => generation.id === openId.value,
        ) ?? null,
);

function reuse(generation: Generation): void {
    openId.value = null;
    form.value?.reuse(generation);
    formSection.value?.scrollIntoView({ behavior: 'smooth' });
}

// Live status. While anything on this page is still on its way, the list is
// fetched again every two seconds. That only reads our own database: news
// from Pixelforge reaches us through webhooks, not through this.
const waiting = computed(() =>
    props.generations.data.some((generation) => !isFinished(generation)),
);

const { start, stop } = usePoll(
    2000,
    { only: ['generations'] },
    { autoStart: false },
);

onMounted(() => {
    watch(waiting, (isWaiting) => (isWaiting ? start() : stop()), {
        immediate: true,
    });
});
</script>

<template>
    <Head title="Studio" />

    <div
        class="grid gap-6 p-4 lg:grid-cols-[minmax(0,24rem)_minmax(0,1fr)] lg:items-start"
    >
        <section
            ref="formSection"
            class="scroll-mt-4 rounded-xl border bg-card p-4 shadow-xs"
            aria-labelledby="new-image-heading"
        >
            <h2 id="new-image-heading" class="mb-4 text-base font-medium">
                New image
            </h2>
            <GenerationForm ref="form" />
        </section>

        <section class="min-w-0" aria-labelledby="your-images-heading">
            <div class="mb-4 flex h-6 items-center justify-between gap-4">
                <h2 id="your-images-heading" class="text-base font-medium">
                    Your images
                </h2>
                <p
                    v-if="waiting"
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <Spinner class="size-3" />
                    Updating live
                </p>
            </div>

            <div
                v-if="generations.data.length === 0"
                class="flex flex-col items-center gap-2 rounded-xl border border-dashed p-12 text-center"
            >
                <Images class="size-8 text-muted-foreground" />
                <p class="text-sm font-medium">No images yet</p>
                <p class="max-w-xs text-sm text-muted-foreground">
                    Describe what you'd like to see, check the price, and press
                    Generate. Your images and what each one cost will appear
                    here.
                </p>
            </div>

            <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <GenerationCard
                    v-for="generation in generations.data"
                    :key="generation.id"
                    :generation="generation"
                    @open="openId = generation.id"
                    @reuse="reuse(generation)"
                />
            </div>

            <nav
                v-if="generations.meta.last_page > 1"
                class="mt-6 flex items-center justify-between gap-4"
                aria-label="Pages of images"
            >
                <Button
                    v-if="generations.links.prev"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="generations.links.prev">
                        <ChevronLeft />
                        Newer
                    </Link>
                </Button>
                <span v-else />

                <span class="text-xs text-muted-foreground">
                    Page {{ generations.meta.current_page }} of
                    {{ generations.meta.last_page }}
                </span>

                <Button
                    v-if="generations.links.next"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="generations.links.next">
                        Older
                        <ChevronRight />
                    </Link>
                </Button>
                <span v-else />
            </nav>
        </section>
    </div>

    <GenerationDialog
        :generation="openGeneration"
        @close="openId = null"
        @reuse="reuse"
    />
</template>
