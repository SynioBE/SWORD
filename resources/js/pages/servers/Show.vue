<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
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
} from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index as serversIndex, show as serversShow } from '@/routes/servers';
import type { BreadcrumbItem } from '@/types';

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
}

const props = defineProps<{
    server: ServerDetail;
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
    if (pollInterval) return;
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
import { watch } from 'vue';
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

// All provisioning steps for progress tracking
const ALL_STEPS = [
    'Checking root access',
    'Setting IPv4 preference',
    'Waiting for package manager',
    'Configuring swap',
    'Upgrading OS packages',
    'Installing extra packages',
    'Ensuring cron is running',
    'Adding Docker GPG key',
    'Adding Docker repository',
    'Installing Docker',
    'Configuring Docker daemon',
    'Restarting Docker',
    'Hardening SSH',
    'Generating SSH key pair',
    'Restarting SSH',
    'Setting hostname',
    'Setting timezone',
    'Creating sword user',
    'Creating sword directories',
    'Adding sword to docker and sudo groups',
    'Configuring sudo for sword',
    'Copying SSH keys for sword',
    'Copying known hosts',
    'Configuring Git',
    'Setting ownership of sword home',
    'Configuring UFW firewall',
    'Configuring unattended security upgrades',
    'Creating global sword directories',
    'Deploying shared services (Traefik, MySQL, Redis)',
    'Starting shared services',
    'Enabling Docker auto-start',
    'Server provisioning complete',
];

const completedSteps = computed(
    () => new Set(props.server.provision_log.map((l) => l.step)),
);

const progressPercent = computed(() => {
    if (isProvisioned.value) return 100;
    if (isPending.value) return 0;
    const done = ALL_STEPS.filter((s) => completedSteps.value.has(s)).length;
    return Math.round((done / ALL_STEPS.length) * 100);
});
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
                            v-for="step in ALL_STEPS"
                            :key="step"
                            class="flex items-center gap-3 px-5 py-2.5 text-sm"
                            :class="{
                                'opacity-40':
                                    !completedSteps.has(step) &&
                                    server.current_step !== step,
                            }"
                        >
                            <CheckCircle2
                                v-if="completedSteps.has(step)"
                                class="size-4 shrink-0 text-green-500"
                            />
                            <Loader2
                                v-else-if="server.current_step === step"
                                class="size-4 shrink-0 animate-spin text-primary"
                            />
                            <div
                                v-else
                                class="size-4 shrink-0 rounded-full border-2 border-muted-foreground/30"
                            />
                            <span
                                :class="{
                                    'font-medium': server.current_step === step,
                                }"
                            >
                                {{ step }}
                            </span>
                            <span
                                v-if="completedSteps.has(step)"
                                class="ml-auto text-xs text-muted-foreground tabular-nums"
                            >
                                {{
                                    formatTime(
                                        server.provision_log.find(
                                            (l) => l.step === step,
                                        )!.timestamp,
                                    )
                                }}
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
                            v-for="entry in server.provision_log"
                            :key="entry.timestamp + entry.step"
                            class="flex items-center gap-3 px-5 py-2.5 text-sm"
                        >
                            <CheckCircle2
                                class="size-4 shrink-0 text-green-500"
                            />
                            <span>{{ entry.step }}</span>
                            <span
                                class="ml-auto text-xs text-muted-foreground tabular-nums"
                            >
                                {{ formatTime(entry.timestamp) }}
                            </span>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
