<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    HardDrive,
    Trash2,
    Loader2,
    Calendar,
    Archive,
    Pencil,
} from 'lucide-vue-next';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    update as destinationsUpdate,
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

interface BackupRunRow {
    id: number;
    server_name: string;
    site_domain: string | null;
    status: string;
    archive_name: string | null;
    size_bytes: number | null;
    duration_seconds: number | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string;
}

const props = defineProps<{
    destination: DestinationDetail;
    schedules: ScheduleRow[];
    recentRuns: BackupRunRow[];
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

function runStatusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'completed':
            return 'default';
        case 'running':
            return 'secondary';
        case 'failed':
            return 'destructive';
        default:
            return 'outline';
    }
}

function formatBytes(bytes: number | null): string {
    if (bytes === null) return '—';
    if (bytes < 1_000) return `${bytes} B`;
    if (bytes < 1_000_000) return `${(bytes / 1_000).toFixed(1)} KB`;
    if (bytes < 1_000_000_000) return `${(bytes / 1_000_000).toFixed(1)} MB`;
    return `${(bytes / 1_000_000_000).toFixed(2)} GB`;
}

function formatDuration(seconds: number | null): string {
    if (seconds === null) return '—';
    if (seconds < 60) return `${seconds}s`;
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    if (mins < 60) return `${mins}m ${secs}s`;
    const hrs = Math.floor(mins / 60);
    return `${hrs}h ${mins % 60}m`;
}

// ── Edit destination ─────────────────────────────────────────
const showEditModal = ref(false);

const editForm = useForm({
    name: props.destination.name,
    type: props.destination.type,
    host: props.destination.host,
    port: props.destination.port,
    username: props.destination.username,
    auth_method: props.destination.auth_method,
    password: '',
    ssh_private_key: '',
    storage_path: props.destination.storage_path,
});

function submitEdit() {
    editForm.put(destinationsUpdate(props.destination.id).url, {
        onSuccess: () => {
            showEditModal.value = false;
        },
    });
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

                    <Button variant="ghost" size="icon" class="mt-1 text-muted-foreground" @click="showEditModal = true">
                        <Pencil class="size-4" />
                    </Button>

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

            <!-- Recent Backup Runs -->
            <div
                class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
            >
                <div
                    class="flex items-center gap-3 border-b border-sidebar-border/70 px-5 py-4 dark:border-sidebar-border"
                >
                    <Archive class="size-5 shrink-0 text-muted-foreground" />
                    <p class="text-sm font-medium">Recent Backup Runs</p>
                    <span class="ml-auto text-xs text-muted-foreground">
                        {{ recentRuns.length }} run{{ recentRuns.length !== 1 ? 's' : '' }}
                    </span>
                </div>

                <div v-if="recentRuns.length === 0" class="px-5 py-8 text-center text-sm text-muted-foreground">
                    No backup runs yet for this destination.
                </div>

                <div v-else class="divide-y divide-sidebar-border/50 dark:divide-sidebar-border/30">
                    <div
                        v-for="run in recentRuns"
                        :key="run.id"
                        class="flex items-center justify-between px-5 py-3"
                    >
                        <div>
                            <p class="text-sm font-medium">
                                {{ run.archive_name ?? run.server_name }}
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ run.server_name }}
                                <template v-if="run.site_domain">
                                    <span class="mx-1.5">·</span>
                                    {{ run.site_domain }}
                                </template>
                                <template v-if="run.duration_seconds !== null">
                                    <span class="mx-1.5">·</span>
                                    {{ formatDuration(run.duration_seconds) }}
                                </template>
                                <template v-if="run.size_bytes !== null">
                                    <span class="mx-1.5">·</span>
                                    {{ formatBytes(run.size_bytes) }}
                                </template>
                                <template v-if="run.completed_at">
                                    <span class="mx-1.5">·</span>
                                    {{ new Date(run.completed_at).toLocaleString() }}
                                </template>
                            </p>
                        </div>
                        <Badge :variant="runStatusVariant(run.status)">
                            <Loader2 v-if="run.status === 'running'" class="size-3 animate-spin" />
                            {{ run.status }}
                        </Badge>
                    </div>
                </div>
            </div>
            <!-- Edit Destination Modal -->
            <Dialog v-model:open="showEditModal">
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Edit Backup Destination</DialogTitle>
                        <DialogDescription>
                            Update the connection details. The connection will be re-tested on save.
                        </DialogDescription>
                    </DialogHeader>

                    <form @submit.prevent="submitEdit" class="flex flex-col gap-4 py-2">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-sm font-medium" for="edit-name">Name</label>
                            <Input id="edit-name" v-model="editForm.name" :disabled="editForm.processing" />
                            <InputError :message="editForm.errors.name" />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-sm font-medium" for="edit-host">Host</label>
                                <Input id="edit-host" v-model="editForm.host" :disabled="editForm.processing" />
                                <InputError :message="editForm.errors.host" />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-sm font-medium" for="edit-port">Port</label>
                                <Input id="edit-port" v-model.number="editForm.port" type="number" :disabled="editForm.processing" />
                                <InputError :message="editForm.errors.port" />
                            </div>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="text-sm font-medium" for="edit-username">Username</label>
                            <Input id="edit-username" v-model="editForm.username" :disabled="editForm.processing" />
                            <InputError :message="editForm.errors.username" />
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="text-sm font-medium" for="edit-auth-method">Authentication</label>
                            <select
                                id="edit-auth-method"
                                v-model="editForm.auth_method"
                                :disabled="editForm.processing"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option value="password">Password</option>
                                <option value="ssh_key">SSH Key</option>
                            </select>
                            <InputError :message="editForm.errors.auth_method" />
                        </div>

                        <div v-if="editForm.auth_method === 'password'" class="flex flex-col gap-1.5">
                            <label class="text-sm font-medium" for="edit-password">Password</label>
                            <Input id="edit-password" v-model="editForm.password" type="password" placeholder="Enter new password" :disabled="editForm.processing" />
                            <InputError :message="editForm.errors.password" />
                        </div>

                        <div v-if="editForm.auth_method === 'ssh_key'" class="flex flex-col gap-1.5">
                            <label class="text-sm font-medium" for="edit-ssh-key">SSH Private Key</label>
                            <textarea
                                id="edit-ssh-key"
                                v-model="editForm.ssh_private_key"
                                placeholder="Paste SSH private key"
                                rows="4"
                                :disabled="editForm.processing"
                                class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            <InputError :message="editForm.errors.ssh_private_key" />
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="text-sm font-medium" for="edit-storage-path">Storage Path</label>
                            <Input id="edit-storage-path" v-model="editForm.storage_path" :disabled="editForm.processing" />
                            <InputError :message="editForm.errors.storage_path" />
                        </div>

                        <DialogFooter class="pt-2">
                            <Button type="button" variant="outline" @click="showEditModal = false" :disabled="editForm.processing">
                                Cancel
                            </Button>
                            <Button type="submit" :disabled="editForm.processing">
                                <Loader2 v-if="editForm.processing" class="size-4 animate-spin" />
                                Save Changes
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
