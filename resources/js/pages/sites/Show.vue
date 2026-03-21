<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Globe,
    CheckCircle2,
    Clock,
    Loader2,
    XCircle,
    Activity,
    Trash2,
    ExternalLink,
    Archive,
    RotateCcw,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import DeploymentCelebration from '@/components/DeploymentCelebration.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { STEP_LABELS, STEP_KEYS } from '@/lib/create-wp-site-steps';
import {
    index as sitesIndex,
    show as sitesShow,
    destroy as sitesDestroy,
    backup as sitesBackup,
    restore as sitesRestore,
} from '@/routes/sites';
import type { BreadcrumbItem } from '@/types';

interface InstallStep {
    step: string;
    timestamp: string;
}

interface BackupRunRow {
    id: number;
    destination_name: string;
    status: string;
    archive_name: string | null;
    size_bytes: number | null;
    duration_seconds: number | null;
    started_at: string | null;
    completed_at: string | null;
}

interface SiteDetail {
    id: number;
    domain: string;
    php_version: string;
    db_name: string;
    status: string;
    current_step: string | null;
    install_log: InstallStep[];
    installed_at: string | null;
    created_at: string;
    server: {
        id: number;
        name: string;
        ip_address: string | null;
    };
}

const props = defineProps<{
    site: SiteDetail;
    backupRuns: BackupRunRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sites', href: sitesIndex() },
    { title: props.site.domain, href: sitesShow(props.site.id) },
];

// ── Live polling while installing ────────────────────────────
let pollInterval: ReturnType<typeof setInterval> | null = null;

const isInstalling = computed(() => props.site.status === 'installing');
const isInstalled = computed(() => props.site.status === 'installed');
const isPending = computed(() => props.site.status === 'pending');
const isFailed = computed(() => props.site.status === 'failed');

function startPolling() {
    if (pollInterval) {
        return;
    }

    pollInterval = setInterval(() => {
        router.reload({ only: ['site'] });
    }, 3000);
}

function stopPolling() {
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
}

onMounted(() => {
    if (isInstalling.value || isPending.value) {
        startPolling();
    }

    // if (isInstalled.value) {
    //     showCelebration.value = true;
    // }
});

onUnmounted(() => {
    stopPolling();
});

watch(
    () => props.site.status,
    (status, prevStatus) => {
        if (status === 'installed' || status === 'failed') {
            stopPolling();
        } else if (status === 'installing' || status === 'pending') {
            startPolling();
        }

        if (
            status === 'installed' &&
            (prevStatus === 'installing' || prevStatus === 'pending')
        ) {
            showCelebration.value = true;
        }
    },
);

// ── Helpers ──────────────────────────────────────────────────
function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'installed':
            return 'default';
        case 'installing':
            return 'secondary';
        case 'failed':
            return 'destructive';
        default:
            return 'outline';
    }
}

function statusLabel(status: string): string {
    switch (status) {
        case 'installed':
            return 'Active';
        case 'installing':
            return 'Installing';
        case 'failed':
            return 'Failed';
        default:
            return 'Pending';
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
    if (isInstalled.value) {
        return STEP_KEYS.length - 1;
    }

    for (let i = props.site.install_log.length - 1; i >= 0; i--) {
        const idx = STEP_KEYS.indexOf(props.site.install_log[i].step);

        if (idx !== -1) {
            return idx;
        }
    }

    return -1;
});

function isStepCompleted(index: number): boolean {
    if (isInstalled.value) {
        return true;
    }

    return index <= lastCompletedIndex.value;
}

// Whether the script has sent its initial "started" ping
const hasStarted = computed(() =>
    props.site.install_log.some((l) => l.step === 'started'),
);

function isStepActive(index: number): boolean {
    if (isInstalled.value) {
        return false;
    }

    if (!hasStarted.value) {
        return false;
    }

    const nextIndex = lastCompletedIndex.value + 1;

    return index === nextIndex && index < STEP_KEYS.length;
}

// ── Backup / Restore ────────────────────────────────────────
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

const backupForm = useForm({});

function createBackup() {
    backupForm.post(sitesBackup(props.site.id).url);
}

const showRestoreDialog = ref(false);
const restoreTarget = ref<BackupRunRow | null>(null);
const restoreForm = useForm({});

function confirmRestore(run: BackupRunRow) {
    restoreTarget.value = run;
    showRestoreDialog.value = true;
}

function submitRestore() {
    if (!restoreTarget.value) return;
    restoreForm.post(
        sitesRestore({ site: props.site.id, backup_run: restoreTarget.value.id }).url,
        {
            onSuccess: () => {
                showRestoreDialog.value = false;
                restoreTarget.value = null;
            },
        },
    );
}

const completedStepKeys = computed(
    () => new Set(props.site.install_log.map((l) => l.step)),
);

const progressPercent = computed(() => {
    if (isInstalled.value) {
        return 100;
    }

    if (isPending.value) {
        return 0;
    }

    const done = STEP_KEYS.filter((k) => completedStepKeys.value.has(k)).length;

    return Math.round((done / STEP_KEYS.length) * 100);
});

function stepTimestamp(key: string): string | null {
    const entry = props.site.install_log.find((l) => l.step === key);

    return entry ? formatTime(entry.timestamp) : null;
}

// ── Celebration on install completion ─────────────────────
const showCelebration = ref(false);

// ── Delete site ───────────────────────────────────────────
const showDeleteDialog = ref(false);
const deleteForm = useForm({
    delete_backups: false,
});

function deleteSite() {
    deleteForm.delete(sitesDestroy(props.site.id).url, {
        onSuccess: () => {
            showDeleteDialog.value = false;
        },
    });
}
</script>

<template>
    <Head :title="site.domain" />

    <!-- Deployment celebration overlay -->
    <DeploymentCelebration
        v-if="showCelebration"
        @done="showCelebration = false"
    />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="mx-auto flex h-full w-full max-w-4xl flex-1 flex-col gap-6 p-6"
        >
            <!-- Site header -->
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="rounded-xl bg-muted p-3">
                        <Globe class="size-6 text-muted-foreground" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">
                            {{ site.domain }}
                        </h1>
                        <div
                            class="mt-1 flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <span>PHP {{ site.php_version }}</span>
                            <span>·</span>
                            <span>{{ site.server.name }}</span>
                            <template v-if="site.server.ip_address">
                                <span>·</span>
                                <span>{{ site.server.ip_address }}</span>
                            </template>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Badge :variant="statusVariant(site.status)" class="mt-1">
                        <CheckCircle2 v-if="isInstalled" class="size-3" />
                        <Loader2
                            v-else-if="isInstalling"
                            class="size-3 animate-spin"
                        />
                        <XCircle v-else-if="isFailed" class="size-3" />
                        <Clock v-else class="size-3" />
                        {{ statusLabel(site.status) }}
                    </Badge>

                    <Button
                        v-if="isInstalled"
                        variant="outline"
                        size="sm"
                        class="mt-1 gap-1.5"
                        as="a"
                        :href="`https://${site.domain}`"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <ExternalLink class="size-3.5" />
                        Visit site
                    </Button>

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
                                <DialogTitle>Delete site</DialogTitle>
                                <DialogDescription>
                                    Are you sure you want to delete
                                    <strong>{{ site.domain }}</strong
                                    >? This will remove the Docker containers,
                                    database, and all site files. This action
                                    cannot be undone.
                                </DialogDescription>
                            </DialogHeader>
                            <div v-if="backupRuns.length > 0" class="flex items-center gap-2 py-2">
                                <Checkbox
                                    id="delete-backups"
                                    :checked="deleteForm.delete_backups"
                                    @update:checked="deleteForm.delete_backups = $event"
                                />
                                <label for="delete-backups" class="text-sm">
                                    Also delete all backups from backup destinations
                                </label>
                            </div>
                            <DialogFooter>
                                <DialogClose as-child>
                                    <Button variant="outline">Cancel</Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    :disabled="deleteForm.processing"
                                    @click="deleteSite"
                                >
                                    <Loader2
                                        v-if="deleteForm.processing"
                                        class="size-4 animate-spin"
                                    />
                                    Delete site
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>

            <!-- Pending / Installing: show live progress -->
            <template v-if="isPending || isInstalling">
                <!-- Live install progress -->
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
                            <div>
                                <p class="text-sm font-medium">
                                    Installing WordPress
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    Sword is automatically installing on
                                    <strong>{{ site.server.name }}</strong> via
                                    SSH.
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <Loader2
                                v-if="isPending || isInstalling"
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
                        Installation failed
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        The installation encountered an unexpected error. Check
                        the server logs for details.
                    </p>
                </div>
            </div>

            <!-- Installed: show log summary -->
            <template v-if="isInstalled">
                <div
                    class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
                >
                    <div
                        class="flex items-center gap-3 border-b border-sidebar-border/70 px-5 py-4 dark:border-sidebar-border"
                    >
                        <Activity
                            class="size-5 shrink-0 text-muted-foreground"
                        />
                        <p class="text-sm font-medium">Install log</p>
                        <span class="ml-auto text-xs text-muted-foreground">
                            Completed
                            {{
                                site.installed_at
                                    ? new Date(
                                          site.installed_at,
                                      ).toLocaleString()
                                    : ''
                            }}
                        </span>
                    </div>
                    <div
                        class="max-h-96 divide-y divide-sidebar-border/50 overflow-y-auto dark:divide-sidebar-border/30"
                    >
                        <div
                            v-for="entry in site.install_log.filter(
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
            </template>

            <!-- Backups -->
            <div
                v-if="isInstalled"
                class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
            >
                <div
                    class="flex items-center gap-3 border-b border-sidebar-border/70 px-5 py-4 dark:border-sidebar-border"
                >
                    <Archive class="size-5 shrink-0 text-muted-foreground" />
                    <p class="text-sm font-medium">Backups</p>
                    <div class="ml-auto flex items-center gap-3">
                        <span v-if="backupRuns.length > 0" class="text-xs text-muted-foreground">
                            {{ backupRuns.length }} backup{{ backupRuns.length !== 1 ? 's' : '' }}
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="backupForm.processing"
                            @click="createBackup"
                        >
                            <Loader2 v-if="backupForm.processing" class="size-3.5 animate-spin" />
                            <Archive v-else class="size-3.5" />
                            Create Backup
                        </Button>
                    </div>
                </div>

                <div v-if="backupForm.errors.backup" class="px-5 py-3">
                    <p class="text-sm text-destructive">{{ backupForm.errors.backup }}</p>
                </div>

                <div v-if="backupRuns.length === 0 && !backupForm.errors.backup" class="px-5 py-8 text-center text-sm text-muted-foreground">
                    No backups yet. Click "Create Backup" to get started.
                </div>

                <div v-else class="divide-y divide-sidebar-border/50 dark:divide-sidebar-border/30">
                    <div
                        v-for="run in backupRuns"
                        :key="run.id"
                        class="flex items-center justify-between px-5 py-3"
                    >
                        <div>
                            <p class="text-sm font-medium">
                                {{ run.archive_name ?? 'Backup #' + run.id }}
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ run.destination_name }}
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
                        <div class="flex items-center gap-2">
                            <Badge :variant="runStatusVariant(run.status)">
                                <Loader2 v-if="run.status === 'running'" class="size-3 animate-spin" />
                                {{ run.status }}
                            </Badge>
                            <Button
                                v-if="run.status === 'completed'"
                                variant="ghost"
                                size="sm"
                                @click="confirmRestore(run)"
                            >
                                <RotateCcw class="size-3.5" />
                                Restore
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Restore confirmation dialog -->
            <Dialog v-model:open="showRestoreDialog">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Restore from backup</DialogTitle>
                        <DialogDescription>
                            This will replace the current site files and database with the backup
                            <strong>{{ restoreTarget?.archive_name }}</strong>.
                            This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose as-child>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            :disabled="restoreForm.processing"
                            @click="submitRestore"
                        >
                            <Loader2 v-if="restoreForm.processing" class="size-4 animate-spin" />
                            Restore backup
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
