<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    Server,
    Plus,
    CheckCircle2,
    Clock,
    Loader2,
    XCircle,
    ChevronRight,
} from 'lucide-vue-next';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { stepLabel } from '@/lib/provision-steps';
import {
    index as serversIndex,
    store as serversStore,
    show as serversShow,
    generateName as serversGenerateName,
} from '@/routes/servers';
import type { BreadcrumbItem } from '@/types';

interface ServerRow {
    id: number;
    name: string;
    ip_address: string | null;
    provider: string | null;
    region: string | null;
    status: string;
    current_step: string | null;
    provisioned_at: string | null;
    created_at: string;
}

const props = defineProps<{
    servers: ServerRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Servers', href: serversIndex() },
];

const showAddModal = ref(false);

// Track whether the user has manually edited the hostname
const hostnameManuallyEdited = ref(false);

const form = useForm({
    name: '',
    hostname: '',
    ip_address: '',
    provider: '',
    region: '',
    timezone: 'UTC',
    ssh_port: 22,
});

// When the dialog opens, fetch a generated name from the backend
watch(showAddModal, async (open) => {
    if (!open) {
        return;
    }

    hostnameManuallyEdited.value = false;

    try {
        const response = await fetch(serversGenerateName.url());
        const data = await response.json();
        form.name = data.name;
        form.hostname = data.hostname;
    } catch {
        // silently ignore; user can type manually
    }
});

// Keep hostname in sync with name as long as the user hasn't manually edited it
watch(
    () => form.name,
    (name) => {
        if (!hostnameManuallyEdited.value) {
            form.hostname = name
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }
    },
);

const timezones = [
    'UTC',
    'America/New_York',
    'America/Chicago',
    'America/Denver',
    'America/Los_Angeles',
    'Europe/London',
    'Europe/Amsterdam',
    'Europe/Berlin',
    'Europe/Paris',
    'Asia/Tokyo',
    'Asia/Singapore',
    'Asia/Dubai',
    'Australia/Sydney',
];

const providers = [
    { value: 'hetzner', label: 'Hetzner Cloud' },
    { value: 'digitalocean', label: 'DigitalOcean' },
    { value: 'linode', label: 'Linode / Akamai' },
    { value: 'vultr', label: 'Vultr' },
    { value: 'aws', label: 'AWS EC2' },
    { value: 'custom', label: 'Custom / Other' },
];

function submitAddServer() {
    form.post(serversStore(), {
        onSuccess: () => {
            showAddModal.value = false;
            form.reset();
        },
    });
}

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
            return 'Pending';
    }
}
</script>

<template>
    <Head title="Servers" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Servers
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Manage your Ubuntu 24.04 servers running Docker &
                        Traefik.
                    </p>
                </div>
                <Button @click="showAddModal = true">
                    <Plus class="size-4" />
                    Add Server
                </Button>
            </div>

            <!-- Empty state -->
            <div
                v-if="props.servers.length === 0"
                class="flex flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-sidebar-border/70 py-20 dark:border-sidebar-border"
            >
                <div class="rounded-full bg-muted p-4">
                    <Server class="size-8 text-muted-foreground" />
                </div>
                <div class="text-center">
                    <p class="font-medium">No servers yet</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Add your first server to get started.
                    </p>
                </div>
                <Button @click="showAddModal = true" variant="outline">
                    <Plus class="size-4" />
                    Add Server
                </Button>
            </div>

            <!-- Server list -->
            <div v-else class="flex flex-col gap-2">
                <Link
                    v-for="server in props.servers"
                    :key="server.id"
                    :href="serversShow(server.id)"
                    class="group flex items-center justify-between rounded-xl border border-sidebar-border/70 bg-card px-5 py-4 transition-colors hover:bg-accent dark:border-sidebar-border"
                >
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-muted p-2">
                            <Server class="size-5 text-muted-foreground" />
                        </div>
                        <div>
                            <p class="leading-none font-medium">
                                {{ server.name }}
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ server.ip_address ?? 'IP not set' }}
                                <span v-if="server.provider" class="mx-1.5"
                                    >·</span
                                >
                                <span
                                    v-if="server.provider"
                                    class="capitalize"
                                    >{{ server.provider }}</span
                                >
                                <span v-if="server.region" class="mx-1.5"
                                    >·</span
                                >
                                <span v-if="server.region">{{
                                    server.region
                                }}</span>
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div
                            v-if="server.status === 'provisioning'"
                            class="flex items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <Loader2 class="size-3.5 animate-spin" />
                            <span>{{ stepLabel(server.current_step) }}</span>
                        </div>
                        <Badge :variant="statusVariant(server.status)">
                            <CheckCircle2
                                v-if="server.status === 'provisioned'"
                                class="size-3"
                            />
                            <Loader2
                                v-else-if="server.status === 'provisioning'"
                                class="size-3 animate-spin"
                            />
                            <XCircle
                                v-else-if="server.status === 'failed'"
                                class="size-3"
                            />
                            <Clock v-else class="size-3" />
                            {{ statusLabel(server.status) }}
                        </Badge>
                        <ChevronRight
                            class="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                        />
                    </div>
                </Link>
            </div>
        </div>

        <!-- Add Server Modal -->
        <Dialog v-model:open="showAddModal">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Add Server</DialogTitle>
                    <DialogDescription>
                        Configure your server details. You'll get a provisioning
                        script to run on a fresh Ubuntu 24.04 install.
                    </DialogDescription>
                </DialogHeader>

                <form
                    @submit.prevent="submitAddServer"
                    class="flex flex-col gap-4 py-2"
                >
                    <div class="flex flex-col gap-1.5">
                        <label class="text-sm font-medium" for="server-name"
                            >Server name</label
                        >
                        <Input
                            id="server-name"
                            v-model="form.name"
                            placeholder="e.g. production-01"
                            :disabled="form.processing"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-sm font-medium" for="server-hostname"
                            >Hostname</label
                        >
                        <Input
                            id="server-hostname"
                            v-model="form.hostname"
                            placeholder="e.g. production-01.example.com"
                            :disabled="form.processing"
                            @input="hostnameManuallyEdited = true"
                        />
                        <InputError :message="form.errors.hostname" />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-sm font-medium" for="server-ip">
                            IP address
                        </label>
                        <Input
                            id="server-ip"
                            v-model="form.ip_address"
                            placeholder="e.g. 65.21.100.42"
                            :disabled="form.processing"
                        />
                        <InputError :message="form.errors.ip_address" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label
                                class="text-sm font-medium"
                                for="server-provider"
                                >Provider</label
                            >
                            <select
                                id="server-provider"
                                v-model="form.provider"
                                :disabled="form.processing"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option value="">Select provider</option>
                                <option
                                    v-for="p in providers"
                                    :key="p.value"
                                    :value="p.value"
                                >
                                    {{ p.label }}
                                </option>
                            </select>
                            <InputError :message="form.errors.provider" />
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label
                                class="text-sm font-medium"
                                for="server-region"
                                >Region</label
                            >
                            <Input
                                id="server-region"
                                v-model="form.region"
                                placeholder="e.g. eu-central"
                                :disabled="form.processing"
                            />
                            <InputError :message="form.errors.region" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label
                                class="text-sm font-medium"
                                for="server-timezone"
                                >Timezone</label
                            >
                            <select
                                id="server-timezone"
                                v-model="form.timezone"
                                :disabled="form.processing"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option
                                    v-for="tz in timezones"
                                    :key="tz"
                                    :value="tz"
                                >
                                    {{ tz }}
                                </option>
                            </select>
                            <InputError :message="form.errors.timezone" />
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label
                                class="text-sm font-medium"
                                for="server-ssh-port"
                                >SSH port</label
                            >
                            <Input
                                id="server-ssh-port"
                                v-model.number="form.ssh_port"
                                type="number"
                                min="1"
                                max="65535"
                                :disabled="form.processing"
                            />
                            <InputError :message="form.errors.ssh_port" />
                        </div>
                    </div>

                    <DialogFooter class="pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="showAddModal = false"
                            :disabled="form.processing"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            <Loader2
                                v-if="form.processing"
                                class="size-4 animate-spin"
                            />
                            Create Server
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
