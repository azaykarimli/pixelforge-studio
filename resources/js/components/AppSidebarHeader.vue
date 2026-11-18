<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import BuyCreditsDialog from '@/components/credits/BuyCreditsDialog.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { formatBalance, formatExact } from '@/lib/money';
import { index as creditsPage } from '@/routes/credits';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();
const credits = computed(() => page.props.credits);
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <!-- What the user can spend right now, on every page. -->
        <div v-if="credits" class="ml-auto flex items-center gap-3">
            <Link
                :href="creditsPage()"
                class="text-sm underline-offset-4 hover:underline"
                :title="`${formatExact(credits.available)} exactly`"
                data-test="available-credit"
            >
                <span class="text-muted-foreground">Credit</span>
                <span class="ml-1 font-medium tabular-nums">{{
                    formatBalance(credits.available)
                }}</span>
            </Link>
            <BuyCreditsDialog />
        </div>
    </header>
</template>
