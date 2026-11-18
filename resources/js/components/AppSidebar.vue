<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { BookOpen, ChartColumn, Sparkles, Wallet } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { spend as spendReport } from '@/routes/admin';
import { index as credits } from '@/routes/credits';
import { index as studio } from '@/routes/generations';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Studio',
        href: studio(),
        icon: Sparkles,
    },
    {
        title: 'Credits',
        href: credits(),
        icon: Wallet,
    },
    // Operators only. The server enforces this too; hiding the link is a courtesy.
    ...(page.props.auth.user.is_admin
        ? [
              {
                  title: 'Spend report',
                  href: spendReport(),
                  icon: ChartColumn,
              },
          ]
        : []),
]);

const footerNavItems: NavItem[] = [
    {
        title: 'Pixelforge API docs',
        href: 'https://pixelforge.promptedit.dev/docs',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="studio()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
