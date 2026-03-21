<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Server,
    CheckCircle2,
    Clock,
    Loader2,
    XCircle,
    Copy,
    Check,
    Terminal,
    Activity,
    Info,
    Trash2,
    Calendar,
    Plus,
} from 'lucide-vue-next';
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { STEP_LABELS, STEP_KEYS } from '@/lib/provision-steps';
import { index as backupDestinationsIndex } from '@/routes/backup-destinations';
import {
    index as serversIndex,
    show as serversShow,
    destroy as serversDestroy,
} from '@/routes/servers';
import {
    store as backupSchedulesStore,
    destroy as backupSchedulesDestroy,
} from '@/routes/servers/backup-schedules';
import type { BreadcrumbItem } from '@/types';

interface BackupScheduleRow {
    id: number;
    backup_destination_id: number;
    destination_name: string;
    frequency: string;
    time: string;
    day_of_week: number | null;
    day_of_month: number | null;
    retention_count: number;
    is_enabled: boolean;
    created_at: string;
}

interface BackupDestinationOption {
    id: number;
    name: string;
}

interface ProvisionStep {
    step: string;
    timestamp: string;
}

interface ServerDetail {
    id: number;
    name: string;
    ip_address: string | null;
    provider: string | null;
    region: string | null;
    status: string;
    current_step: string | null;
    provision_log: ProvisionStep[];
    provisioned_at: string | null;
    created_at: string;
    wget_command: string;
    callback_signature: string;
    is_online: boolean;
    last_pinged_at: string | null;
}

const props = defineProps<{
    server: ServerDetail;
    backupSchedules: BackupScheduleRow[];
    backupDestinations: BackupDestinationOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Servers', href: serversIndex() },
    { title: props.server.name, href: serversShow(props.server.id) },
];

// ── Copy wget command ────────────────────────────────────────
const copied = ref(false);

async function copyCommand() {
    await navigator.clipboard.writeText(props.server.wget_command);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 2000);
}

// ── Live polling while provisioning ─────────────────────────
let pollInterval: ReturnType<typeof setInterval> | null = null;

const isProvisioning = computed(() => props.server.status === 'provisioning');
const isProvisioned = computed(() => props.server.status === 'provisioned');
const isPending = computed(() => props.server.status === 'pending');
const isFailed = computed(() => props.server.status === 'failed');

function startPolling() {
    if (pollInterval) {
        return;
    }

    pollInterval = setInterval(() => {
        router.reload({ only: ['server'] });
    }, 3000);
}

function stopPolling() {
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
}

onMounted(() => {
    if (isProvisioning.value || isPending.value) {
        startPolling();
    }
});

onUnmounted(() => {
    stopPolling();
});

// Watch for status change — stop polling when done
watch(
    () => props.server.status,
    (status) => {
        if (status === 'provisioned' || status === 'failed') {
            stopPolling();
        } else if (status === 'provisioning' || status === 'pending') {
            startPolling();
        }
    },
);

// ── Helpers ──────────────────────────────────────────────────
function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'provisioned':
            return 'default';
        case 'provisioning':
            return 'secondary';
        case 'failed':
            return 'destructive';
        default:
            return 'outline';
    }
}

function statusLabel(status: string): string {
    switch (status) {
        case 'provisioned':
            return 'Active';
        case 'provisioning':
            return 'Provisioning';
        case 'failed':
            return 'Failed';
        default:
            return 'Pending setup';
    }
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

// The index of the last logged step (by log order, not step order) — supports re-runs
const lastCompletedIndex = computed(() => {
    if (isProvisioned.value) {
        return STEP_KEYS.length - 1;
    }

    for (let i = props.server.provision_log.length - 1; i >= 0; i--) {
        const idx = STEP_KEYS.indexOf(props.server.provision_log[i].step);

        if (idx !== -1) {
            return idx;
        }
    }

    return -1;
});

function isStepCompleted(index: number): boolean {
    if (isProvisioned.value) {
        return true;
    }

    // A step is completed if a later or equal step was logged
    return index <= lastCompletedIndex.value;
}

// Whether the script has sent its initial "started" ping
const hasStarted = computed(() =>
    props.server.provision_log.some((l) => l.step === 'started'),
);

function isStepActive(index: number): boolean {
    if (isProvisioned.value) {
        return false;
    }

    if (!hasStarted.value) {
        return false;
    }

    // Show spinner on the step right after the last completed one
    const nextIndex = lastCompletedIndex.value + 1;

    return index === nextIndex && index < STEP_KEYS.length;
}

const completedStepKeys = computed(
    () => new Set(props.server.provision_log.map((l) => l.step)),
);

const progressPercent = computed(() => {
    if (isProvisioned.value) {
        return 100;
    }

    if (isPending.value) {
        return 0;
    }

    const done = STEP_KEYS.filter((k) => completedStepKeys.value.has(k)).length;

    return Math.round((done / STEP_KEYS.length) * 100);
});

function stepTimestamp(key: string): string | null {
    const entry = props.server.provision_log.find((l) => l.step === key);

    return entry ? formatTime(entry.timestamp) : null;
}

// ── Backup schedules ─────────────────────────────────────────
const showAddScheduleModal = ref(false);
const showNoDestinationsDialog = ref(false);
const showDeleteScheduleDialog = ref(false);
const scheduleToDelete = ref<BackupScheduleRow | null>(null);

const scheduleForm = useForm({
    backup_destination_id: '',
    frequency: 'daily',
    time: '02:00',
    day_of_week: 0,
    day_of_month: 1,
    retention_count: 7,
});

function handleAddSchedule() {
    if (props.backupDestinations.length === 0) {
        showNoDestinationsDialog.value = true;
    } else {
        showAddScheduleModal.value = true;
    }
}

function submitAddSchedule() {
    scheduleForm.post(backupSchedulesStore(props.server.id).url, {
        onSuccess: () => {
            showAddScheduleModal.value = false;
            scheduleForm.reset();
        },
    });
}

const deleteScheduleForm = useForm({});

function confirmDeleteSchedule(schedule: BackupScheduleRow) {
    scheduleToDelete.value = schedule;
    showDeleteScheduleDialog.value = true;
}

function deleteSchedule() {
    if (!scheduleToDelete.value) {
        return;
    }

    deleteScheduleForm.delete(
        backupSchedulesDestroy({
            server: props.server.id,
            backup_schedule: scheduleToDelete.value.id,
        }).url,
        {
            onSuccess: () => {
                showDeleteScheduleDialog.value = false;
                scheduleToDelete.value = null;
            },
        },
    );
}

const dayNames = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];

function scheduleLabel(schedule: BackupScheduleRow): string {
    if (schedule.frequency === 'weekly' && schedule.day_of_week !== null) {
        return `Weekly on ${dayNames[schedule.day_of_week]} at ${schedule.time}`;
    }

    if (schedule.frequency === 'monthly' && schedule.day_of_month !== null) {
        return `Monthly on day ${schedule.day_of_month} at ${schedule.time}`;
    }

    return `Daily at ${schedule.time}`;
}

// ── Delete server ────────────────────────────────────────────
const showDeleteDialog = ref(false);
const deleteForm = useForm({});

function deleteServer() {
    deleteForm.delete(serversDestroy(props.server.id).url, {
        onSuccess: () => {
            showDeleteDialog.value = false;
        },
    });
}
</script>

<template>
    <Head :title="server.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="mx-auto flex h-full w-full max-w-4xl flex-1 flex-col gap-6 p-6"
        >
            <!-- Server header -->
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="rounded-xl bg-muted p-3">
                        <Server class="size-6 text-muted-foreground" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">
                            {{ server.name }}
                        </h1>
                        <div
                            class="mt-1 flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <span>{{ server.ip_address ?? 'IP not set' }}</span>
                            <template v-if="server.provider">
                                <span>·</span>
                                <span class="capitalize">{{
                                    server.provider
                                }}</span>
                            </template>
                            <template v-if="server.region">
                                <span>·</span>
                                <span>{{ server.region }}</span>
                            </template>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Badge
                        v-if="isProvisioned"
                        variant="outline"
                        class="mt-1 gap-1.5"
                    >
                        <span
                            class="size-1.5 rounded-full"
                            :class="
                                server.is_online
                                    ? 'bg-green-500'
                                    : 'animate-pulse bg-red-500'
                            "
                        />
                        {{ server.is_online ? 'Online' : 'Offline' }}
                    </Badge>

                    <Badge :variant="statusVariant(server.status)" class="mt-1">
                        <CheckCircle2 v-if="isProvisioned" class="size-3" />
                        <Loader2
                            v-else-if="isProvisioning"
                            class="size-3 animate-spin"
                        />
                        <XCircle v-else-if="isFailed" class="size-3" />
                        <Clock v-else class="size-3" />
                        {{ statusLabel(server.status) }}
                    </Badge>

                    <Dialog v-model:open="showDeleteDialog">
                        <DialogTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="mt-1 text-muted-foreground hover:text-destructive"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Delete server</DialogTitle>
                                <DialogDescription>
                                    Are you sure you want to delete
                                    <strong>{{ server.name }}</strong
                                    >? This action cannot be undone.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <DialogClose as-child>
                                    <Button variant="outline">Cancel</Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    :disabled="deleteForm.processing"
                                    @click="deleteServer"
                                >
                                    <Loader2
                                        v-if="deleteForm.processing"
                                        class="size-4 animate-spin"
                                    />
                                    Delete server
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>

            <!-- Pending: show provisioning instructions -->
            <template v-if="isPending || isProvisioning">
                <!-- Instructions card -->
                <div
                    class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
                >
                    <div
                        class="flex items-center gap-3 border-b border-sidebar-border/70 px-5 py-4 dark:border-sidebar-border"
                    >
                        <Terminal
                            class="size-5 shrink-0 text-muted-foreground"
                        />
                        <div>
                            <p class="text-sm font-medium">
                                Provision this server
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                Run this command on a fresh
                                <strong>Ubuntu 24.04</strong> server as root.
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-col gap-3 p-5">
                        <div
                            class="group relative rounded-lg border border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border"
                        >
                            <pre
                                class="overflow-x-auto p-4 font-mono text-sm leading-relaxed break-all whitespace-pre-wrap text-foreground"
                                >{{ server.wget_command }}</pre
                            >
                            <Button
                                variant="ghost"
                                size="icon"
                                class="absolute top-2 right-2 size-7 opacity-0 transition-opacity group-hover:opacity-100"
                                @click="copyCommand"
                                :title="copied ? 'Copied!' : 'Copy command'"
                            >
                                <Check
                                    v-if="copied"
                                    class="size-3.5 text-green-500"
                                />
                                <Copy v-else class="size-3.5" />
                            </Button>
                        </div>
                        <div
                            class="flex items-start gap-2 text-xs text-muted-foreground"
                        >
                            <Info class="mt-0.5 size-3.5 shrink-0" />
                            <span>
                                The script will ping this dashboard after each
                                step. This page updates automatically.
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Live provisioning progress -->
                <div
                    class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
                >
                    <div
                        class="flex items-center justify-between gap-3 border-b border-sidebar-border/70 px-5 py-4 dark:border-sidebar-border"
                    >
                        <div class="flex items-center gap-3">
                            <Activity
                                class="size-5 shrink-0 text-muted-foreground"
                            />
                            <p class="text-sm font-medium">
                                Provisioning progress
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <Loader2
                                v-if="isProvisioning"
                                class="size-3.5 animate-spin text-muted-foreground"
                            />
                            <span
                                class="text-xs text-muted-foreground tabular-nums"
                                >{{ progressPercent }}%</span
                            >
                        </div>
                    </div>

                    <!-- Progress bar -->
                    <div class="h-1 bg-muted">
                        <div
                            class="h-full bg-primary transition-all duration-500"
                            :style="{ width: progressPercent + '%' }"
                        />
                    </div>

                    <!-- Step list -->
                    <div
                        class="max-h-96 divide-y divide-sidebar-border/50 overflow-y-auto dark:divide-sidebar-border/30"
                    >
                        <div
                            v-for="(key, index) in STEP_KEYS"
                            :key="key"
                            class="flex items-center gap-3 px-5 py-2.5 text-sm"
                            :class="{
                                'opacity-40':
                                    !isStepCompleted(index) &&
                                    !isStepActive(index),
                            }"
                        >
                            <CheckCircle2
                                v-if="isStepCompleted(index)"
                                class="size-4 shrink-0 text-green-500"
                            />
                            <Loader2
                                v-else-if="isStepActive(index)"
                                class="size-4 shrink-0 animate-spin text-primary"
                            />
                            <div
                                v-else
                                class="size-4 shrink-0 rounded-full border-2 border-muted-foreground/30"
                            />
                            <span
                                :class="{
                                    'font-medium': isStepActive(index),
                                }"
                            >
                                {{ STEP_LABELS[key] }}
                            </span>
                            <span
                                v-if="stepTimestamp(key)"
                                class="ml-auto text-xs text-muted-foreground tabular-nums"
                            >
                                {{ stepTimestamp(key) }}
                            </span>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Failed state -->
            <div
                v-if="isFailed"
                class="flex items-start gap-3 rounded-xl border border-destructive/50 bg-destructive/5 p-5"
            >
                <XCircle class="mt-0.5 size-5 shrink-0 text-destructive" />
                <div>
                    <p class="font-medium text-destructive">
                        Provisioning failed
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        The script encountered an unexpected error. Check your
                        server logs (<code class="text-xs"
                            >sword-provision.log</code
                        >) for details, then try provisioning again.
                    </p>
                </div>
            </div>

            <!-- Provisioned: show log summary -->
            <template v-if="isProvisioned">
                <div
                    class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
                >
                    <div
                        class="flex items-center gap-3 border-b border-sidebar-border/70 px-5 py-4 dark:border-sidebar-border"
                    >
                        <Activity
                            class="size-5 shrink-0 text-muted-foreground"
                        />
                        <p class="text-sm font-medium">Provision log</p>
                        <span class="ml-auto text-xs text-muted-foreground">
                            Completed
                            {{
                                server.provisioned_at
                                    ? new Date(
                                          server.provisioned_at,
                                      ).toLocaleString()
                                    : ''
                            }}
                        </span>
                    </div>
                    <div
                        class="max-h-96 divide-y divide-sidebar-border/50 overflow-y-auto dark:divide-sidebar-border/30"
                    >
                        <div
                            v-for="entry in server.provision_log.filter(
                                (l) => l.step !== 'started',
                            )"
                            :key="entry.timestamp + entry.step"
                            class="flex items-center gap-3 px-5 py-2.5 text-sm"
                        >
                            <CheckCircle2
                                class="size-4 shrink-0 text-green-500"
                            />
                            <span>{{
                                STEP_LABELS[entry.step] ?? entry.step
                            }}</span>
                            <span
                                class="ml-auto text-xs text-muted-foreground tabular-nums"
                            >
                                {{ formatTime(entry.timestamp) }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Backup Schedules -->
                <div
                    class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
                >
                    <div
                        class="flex items-center gap-3 border-b border-sidebar-border/70 px-5 py-4 dark:border-sidebar-border"
                    >
                        <Calendar
                            class="size-5 shrink-0 text-muted-foreground"
                        />
                        <p class="text-sm font-medium">Backup Schedules</p>
                        <span class="ml-auto">
                            <Button
                                size="sm"
                                variant="outline"
                                @click="handleAddSchedule"
                            >
                                <Plus class="size-3.5" />
                                Add Schedule
                            </Button>
                        </span>
                    </div>

                    <div
                        v-if="backupSchedules.length === 0"
                        class="px-5 py-8 text-center text-sm text-muted-foreground"
                    >
                        <template v-if="backupDestinations.length === 0">
                            Create a backup destination first to schedule
                            backups.
                        </template>
                        <template v-else>
                            No backup schedules configured for this server.
                        </template>
                    </div>

                    <div
                        v-else
                        class="divide-y divide-sidebar-border/50 dark:divide-sidebar-border/30"
                    >
                        <div
                            v-for="schedule in backupSchedules"
                            :key="schedule.id"
                            class="flex items-center justify-between px-5 py-3"
                        >
                            <div>
                                <p class="text-sm font-medium">
                                    {{ schedule.destination_name }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ scheduleLabel(schedule) }}
                                    <span class="mx-1.5">·</span>
                                    Retain
                                    {{ schedule.retention_count }} backups
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <Badge
                                    :variant="
                                        schedule.is_enabled
                                            ? 'default'
                                            : 'outline'
                                    "
                                >
                                    {{
                                        schedule.is_enabled
                                            ? 'Enabled'
                                            : 'Disabled'
                                    }}
                                </Badge>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-7 text-muted-foreground hover:text-destructive"
                                    @click="confirmDeleteSchedule(schedule)"
                                >
                                    <Trash2 class="size-3.5" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Add Schedule Modal -->
            <Dialog v-model:open="showAddScheduleModal">
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Add Backup Schedule</DialogTitle>
                        <DialogDescription>
                            Configure when this server should be backed up.
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        @submit.prevent="submitAddSchedule"
                        class="flex flex-col gap-4 py-2"
                    >
                        <div class="flex flex-col gap-1.5">
                            <label
                                class="text-sm font-medium"
                                for="schedule-dest"
                                >Backup Destination</label
                            >
                            <select
                                id="schedule-dest"
                                v-model="scheduleForm.backup_destination_id"
                                :disabled="scheduleForm.processing"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option value="">Select destination</option>
                                <option
                                    v-for="dest in backupDestinations"
                                    :key="dest.id"
                                    :value="dest.id"
                                >
                                    {{ dest.name }}
                                </option>
                            </select>
                            <InputError
                                :message="
                                    scheduleForm.errors.backup_destination_id
                                "
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1.5">
                                <label
                                    class="text-sm font-medium"
                                    for="schedule-frequency"
                                    >Frequency</label
                                >
                                <select
                                    id="schedule-frequency"
                                    v-model="scheduleForm.frequency"
                                    :disabled="scheduleForm.processing"
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                </select>
                                <InputError
                                    :message="scheduleForm.errors.frequency"
                                />
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label
                                    class="text-sm font-medium"
                                    for="schedule-time"
                                    >Time</label
                                >
                                <Input
                                    id="schedule-time"
                                    v-model="scheduleForm.time"
                                    type="time"
                                    :disabled="scheduleForm.processing"
                                />
                                <InputError
                                    :message="scheduleForm.errors.time"
                                />
                            </div>
                        </div>

                        <div
                            v-if="scheduleForm.frequency === 'weekly'"
                            class="flex flex-col gap-1.5"
                        >
                            <label
                                class="text-sm font-medium"
                                for="schedule-dow"
                                >Day of Week</label
                            >
                            <select
                                id="schedule-dow"
                                v-model.number="scheduleForm.day_of_week"
                                :disabled="scheduleForm.processing"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option
                                    v-for="(name, idx) in dayNames"
                                    :key="idx"
                                    :value="idx"
                                >
                                    {{ name }}
                                </option>
                            </select>
                            <InputError
                                :message="scheduleForm.errors.day_of_week"
                            />
                        </div>

                        <div
                            v-if="scheduleForm.frequency === 'monthly'"
                            class="flex flex-col gap-1.5"
                        >
                            <label
                                class="text-sm font-medium"
                                for="schedule-dom"
                                >Day of Month</label
                            >
                            <Input
                                id="schedule-dom"
                                v-model.number="scheduleForm.day_of_month"
                                type="number"
                                min="1"
                                max="28"
                                :disabled="scheduleForm.processing"
                            />
                            <InputError
                                :message="scheduleForm.errors.day_of_month"
                            />
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label
                                class="text-sm font-medium"
                                for="schedule-retention"
                                >Retention count</label
                            >
                            <Input
                                id="schedule-retention"
                                v-model.number="scheduleForm.retention_count"
                                type="number"
                                min="1"
                                max="365"
                                :disabled="scheduleForm.processing"
                            />
                            <InputError
                                :message="scheduleForm.errors.retention_count"
                            />
                        </div>

                        <DialogFooter class="pt-2">
                            <Button
                                type="button"
                                variant="outline"
                                @click="showAddScheduleModal = false"
                                :disabled="scheduleForm.processing"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                :disabled="scheduleForm.processing"
                            >
                                <Loader2
                                    v-if="scheduleForm.processing"
                                    class="size-4 animate-spin"
                                />
                                Create Schedule
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <!-- No Destinations Dialog -->
            <Dialog v-model:open="showNoDestinationsDialog">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>No backup destinations</DialogTitle>
                        <DialogDescription>
                            You need to create a backup destination before you
                            can schedule backups. Backup destinations define
                            where your backups are stored.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            @click="showNoDestinationsDialog = false"
                            >Cancel</Button
                        >
                        <Link :href="backupDestinationsIndex()">
                            <Button>Go to Backup Destinations</Button>
                        </Link>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <!-- Delete Schedule Confirmation -->
            <Dialog v-model:open="showDeleteScheduleDialog">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete backup schedule</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to remove this backup
                            schedule? This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            @click="showDeleteScheduleDialog = false"
                            >Cancel</Button
                        >
                        <Button
                            variant="destructive"
                            :disabled="deleteScheduleForm.processing"
                            @click="deleteSchedule"
                        >
                            <Loader2
                                v-if="deleteScheduleForm.processing"
                                class="size-4 animate-spin"
                            />
                            Delete schedule
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
