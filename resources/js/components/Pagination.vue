<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

// Lists here run newest first, so the previous page holds newer items.
defineProps<{
    page: Pick<Paginated<unknown>, 'links' | 'meta'>;
}>();
</script>

<template>
    <nav
        v-if="page.meta.last_page > 1"
        class="flex items-center justify-between gap-4"
        aria-label="Pages"
    >
        <Button v-if="page.links.prev" variant="outline" size="sm" as-child>
            <Link :href="page.links.prev">
                <ChevronLeft />
                Newer
            </Link>
        </Button>
        <span v-else />

        <span class="text-xs text-muted-foreground">
            Page {{ page.meta.current_page }} of {{ page.meta.last_page }}
        </span>

        <Button v-if="page.links.next" variant="outline" size="sm" as-child>
            <Link :href="page.links.next">
                Older
                <ChevronRight />
            </Link>
        </Button>
        <span v-else />
    </nav>
</template>
