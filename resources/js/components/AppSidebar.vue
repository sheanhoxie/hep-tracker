<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { CalendarCheck, ClipboardList, Dumbbell, History, LayoutGrid, Wrench } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();
const isManager = computed(() => ['practitioner', 'admin'].includes(page.props.auth.user.role));

const patientNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Today',
        href: '/today',
        icon: CalendarCheck,
    },
    {
        title: 'Library',
        href: '/library',
        icon: Dumbbell,
    },
    {
        title: 'History',
        href: '/history',
        icon: History,
    },
];

const adminNavItems: NavItem[] = [
    {
        title: 'Exercises',
        href: '/admin/exercises',
        icon: Wrench,
    },
    {
        title: 'Program',
        href: '/admin/program',
        icon: ClipboardList,
    },
];

const mainNavItems = computed<NavItem[]>(() => (isManager.value ? [...patientNavItems, ...adminNavItems] : patientNavItems));

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
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
