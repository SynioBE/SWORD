<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CalendarClock,
    ChevronRight,
} from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/AppLayout.vue';
import { show as serversShow } from '@/routes/servers';
import type { BreadcrumbItem } from '@/types';

interface ScheduleRow {
    id: number;
    server_id: number;
    server_name: string;
    destination_name: string;
    frequency: string;
    time: string;
    day_of_week: number | null;
    day_of_month: number | null;
    retention_count: number;
    is_enabled: boolean;
    created_at: string;
}

const props = defineProps<{
    schedules: ScheduleRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backup Schedules', href: '/backup-schedules' },
];

const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

function scheduleLabel(schedule: ScheduleRow): string {
    if (schedule.frequency === 'weekly' && schedule.day_of_week !== null) {
        return `Weekly on ${dayNames[schedule.day_of_week]} at ${schedule.time}`;
    }

    if (schedule.frequency === 'monthly' && schedule.day_of_month !== null) {
        return `Monthly on day ${schedule.day_of_month} at ${schedule.time}`;
    }

    return `Daily at ${schedule.time}`;
}
</script>

<template>
    <Head title="Backup Schedules" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Backup Schedules
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        All scheduled backups across your servers.
                    </p>
                </div>
            </div>

            <!-- Empty state -->
            <div
                v-if="props.schedules.length === 0"
                class="flex flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-sidebar-border/70 py-20 dark:border-sidebar-border"
            >
                <div class="rounded-full bg-muted p-4">
                    <CalendarClock class="size-8 text-muted-foreground" />
                </div>
                <div class="text-center">
                    <p class="font-medium">No backup schedules yet</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Add backup schedules from a server's detail page.
                    </p>
                </div>
            </div>

            <!-- Schedule list -->
            <div v-else class="flex flex-col gap-2">
                <Link
                    v-for="schedule in props.schedules"
                    :key="schedule.id"
                    :href="serversShow(schedule.server_id)"
                    class="group flex items-center justify-between rounded-xl border border-sidebar-border/70 bg-card px-5 py-4 transition-colors hover:bg-accent dark:border-sidebar-border"
                >
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-muted p-2">
                            <CalendarClock class="size-5 text-muted-foreground" />
                        </div>
                        <div>
                            <p class="leading-none font-medium">
                                {{ schedule.server_name }}
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ schedule.destination_name }}
                                <span class="mx-1.5">·</span>
                                {{ scheduleLabel(schedule) }}
                                <span class="mx-1.5">·</span>
                                Retain {{ schedule.retention_count }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <Badge :variant="schedule.is_enabled ? 'default' : 'outline'">
                            {{ schedule.is_enabled ? 'Enabled' : 'Disabled' }}
                        </Badge>
                        <ChevronRight
                            class="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                        />
                    </div>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
