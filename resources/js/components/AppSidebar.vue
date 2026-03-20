<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CalendarClock,
    Database,
    FolderGit2,
    Server,
} from 'lucide-vue-next';
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
import { index as backupDestinationsIndex } from '@/routes/backup-destinations';
import { index as backupSchedulesIndex } from '@/routes/backup-schedules';
import { index as serversIndex } from '@/routes/servers';
import type { NavItem } from '@/types';

const platformNavItems: NavItem[] = [
    {
        title: 'Servers',
        href: serversIndex(),
        icon: Server,
    },
];

const backupsNavItems: NavItem[] = [
    {
        title: 'Destinations',
        href: backupDestinationsIndex(),
        icon: Database,
    },
    {
        title: 'Schedules',
        href: backupSchedulesIndex(),
        icon: CalendarClock,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/SynioBE/SWORD',
        icon: FolderGit2,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="serversIndex()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain label="Platform" :items="platformNavItems" />
            <NavMain label="Backups" :items="backupsNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
