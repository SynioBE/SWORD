<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    HardDrive,
    Trash2,
    Loader2,
    Calendar,
} from 'lucide-vue-next';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
    DialogClose,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    index as destinationsIndex,
    show as destinationsShow,
    destroy as destinationsDestroy,
} from '@/routes/backup-destinations';
import type { BreadcrumbItem } from '@/types';

interface DestinationDetail {
    id: number;
    name: string;
    type: string;
    host: string;
    port: number;
    username: string;
    auth_method: string;
    storage_path: string;
    status: string;
    last_connected_at: string | null;
    created_at: string;
}

interface ScheduleRow {
    id: number;
    server_name: string;
    server_id: number;
    frequency: string;
    time: string;
    day_of_week: number | null;
    day_of_month: number | null;
    retention_count: number;
    is_enabled: boolean;
    created_at: string;
}

const props = defineProps<{
    destination: DestinationDetail;
    schedules: ScheduleRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backup Destinations', href: destinationsIndex() },
    { title: props.destination.name, href: destinationsShow(props.destination.id) },
];

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'connected':
            return 'default';
        case 'pending':
            return 'outline';
        case 'error':
            return 'destructive';
        default:
            return 'secondary';
    }
}

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

// ── Delete destination ────────────────────────────────────────
const showDeleteDialog = ref(false);
const deleteForm = useForm({});

function deleteDestination() {
    deleteForm.delete(destinationsDestroy(props.destination.id).url, {
        onSuccess: () => {
            showDeleteDialog.value = false;
        },
    });
}
</script>

<template>
    <Head :title="destination.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="mx-auto flex h-full w-full max-w-4xl flex-1 flex-col gap-6 p-6"
        >
            <!-- Header -->
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="rounded-xl bg-muted p-3">
                        <HardDrive class="size-6 text-muted-foreground" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">
                            {{ destination.name }}
                        </h1>
                        <div
                            class="mt-1 flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <span>{{ destination.username }}@{{ destination.host }}:{{ destination.port }}</span>
                            <span>·</span>
                            <span>{{ destination.storage_path }}</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Badge :variant="statusVariant(destination.status)" class="mt-1 capitalize">
                        {{ destination.status }}
                    </Badge>

                    <Dialog v-model:open="showDeleteDialog">
                        <DialogTrigger as-child>
                            <Button variant="ghost" size="icon" class="mt-1 text-muted-foreground hover:text-destructive">
                                <Trash2 class="size-4" />
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Delete backup destination</DialogTitle>
                                <DialogDescription>
                                    Are you sure you want to delete <strong>{{ destination.name }}</strong>? All associated backup schedules will also be removed. This action cannot be undone.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <DialogClose as-child>
                                    <Button variant="outline">Cancel</Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    :disabled="deleteForm.processing"
                                    @click="deleteDestination"
                                >
                                    <Loader2 v-if="deleteForm.processing" class="size-4 animate-spin" />
                                    Delete destination
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>

            <!-- Details card -->
            <div
                class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
            >
                <div
                    class="flex items-center gap-3 border-b border-sidebar-border/70 px-5 py-4 dark:border-sidebar-border"
                >
                    <HardDrive class="size-5 shrink-0 text-muted-foreground" />
                    <p class="text-sm font-medium">Connection Details</p>
                </div>
                <div class="grid grid-cols-2 gap-4 p-5">
                    <div>
                        <p class="text-xs text-muted-foreground">Type</p>
                        <p class="mt-0.5 text-sm font-medium uppercase">{{ destination.type }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Authentication</p>
                        <p class="mt-0.5 text-sm font-medium capitalize">{{ destination.auth_method.replace('_', ' ') }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Host</p>
                        <p class="mt-0.5 text-sm font-medium">{{ destination.host }}:{{ destination.port }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Username</p>
                        <p class="mt-0.5 text-sm font-medium">{{ destination.username }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Storage Path</p>
                        <p class="mt-0.5 text-sm font-medium">{{ destination.storage_path }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Last Connected</p>
                        <p class="mt-0.5 text-sm font-medium">
                            {{ destination.last_connected_at ? new Date(destination.last_connected_at).toLocaleString() : 'Never' }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Schedules using this destination -->
            <div
                class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
            >
                <div
                    class="flex items-center gap-3 border-b border-sidebar-border/70 px-5 py-4 dark:border-sidebar-border"
                >
                    <Calendar class="size-5 shrink-0 text-muted-foreground" />
                    <p class="text-sm font-medium">Backup Schedules</p>
                    <span class="ml-auto text-xs text-muted-foreground">
                        {{ schedules.length }} schedule{{ schedules.length !== 1 ? 's' : '' }}
                    </span>
                </div>

                <div v-if="schedules.length === 0" class="px-5 py-8 text-center text-sm text-muted-foreground">
                    No servers are using this backup destination yet.
                </div>

                <div v-else class="divide-y divide-sidebar-border/50 dark:divide-sidebar-border/30">
                    <div
                        v-for="schedule in schedules"
                        :key="schedule.id"
                        class="flex items-center justify-between px-5 py-3"
                    >
                        <div>
                            <p class="text-sm font-medium">{{ schedule.server_name }}</p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ scheduleLabel(schedule) }}
                                <span class="mx-1.5">·</span>
                                Retain {{ schedule.retention_count }} backups
                            </p>
                        </div>
                        <Badge :variant="schedule.is_enabled ? 'default' : 'outline'">
                            {{ schedule.is_enabled ? 'Enabled' : 'Disabled' }}
                        </Badge>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
