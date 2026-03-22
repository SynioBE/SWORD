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
import { ref, watch, computed } from 'vue';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout.vue';
import { stepLabel } from '@/lib/provision-steps';
import {
    index as serversIndex,
    store as serversStore,
    show as serversShow,
    generateName as serversGenerateName,
} from '@/routes/servers';
import { index as integrationsIndex } from '@/routes/integrations';
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
    is_online: boolean;
}

interface CloudIntegration {
    id: number;
    name: string;
    provider: string;
}

const props = defineProps<{
    servers: ServerRow[];
    cloudIntegrations: CloudIntegration[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Servers', href: serversIndex() },
];

const showAddModal = ref(false);

// Track whether the user has manually edited the hostname
const hostnameManuallyEdited = ref(false);

const hetznerIntegrations = computed(() =>
    props.cloudIntegrations.filter((i) => i.provider === 'hetzner'),
);

const doIntegrations = computed(() =>
    props.cloudIntegrations.filter((i) => i.provider === 'digital_ocean'),
);

const defaultTab = computed(() => {
    if (hetznerIntegrations.value.length > 0) return 'hetzner';
    if (doIntegrations.value.length > 0) return 'digital_ocean';
    return 'custom';
});

const activeTab = ref(defaultTab.value);

const form = useForm({
    name: '',
    hostname: '',
    ip_address: '',
    provider: '',
    region: '',
    timezone: 'UTC',
    ssh_port: 22,
    integration_id: null as number | null,
    server_type: '',
    image: '',
});

// When the dialog opens, fetch a generated name and reset tab state
watch(showAddModal, async (open) => {
    if (!open) {
        return;
    }

    hostnameManuallyEdited.value = false;
    activeTab.value = defaultTab.value;
    form.reset();

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

// Clear region when Hetzner server type changes (different arch = different locations)
watch(
    () => form.server_type,
    () => {
        if (activeTab.value === 'hetzner') {
            form.region = '';
        }
    },
);

// When tab changes, clear provider-specific fields and set integration
watch(activeTab, (tab) => {
    form.integration_id = null;
    form.server_type = '';
    form.region = '';
    form.image = '';
    form.ip_address = '';
    form.provider = '';

    if (tab === 'hetzner' && hetznerIntegrations.value.length > 0) {
        form.integration_id = hetznerIntegrations.value[0].id;
    } else if (tab === 'digital_ocean' && doIntegrations.value.length > 0) {
        form.integration_id = doIntegrations.value[0].id;
    }
});

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

const customProviders = [
    { value: 'hetzner', label: 'Hetzner Cloud' },
    { value: 'digitalocean', label: 'DigitalOcean' },
    { value: 'linode', label: 'Linode / Akamai' },
    { value: 'vultr', label: 'Vultr' },
    { value: 'aws', label: 'AWS EC2' },
    { value: 'custom', label: 'Custom / Other' },
];

const hetznerServerTypes = [
    { value: 'cax11', label: 'CAX11 — 2 vCPU, 4 GB (ARM)', arch: 'arm' },
    { value: 'cax21', label: 'CAX21 — 4 vCPU, 8 GB (ARM)', arch: 'arm' },
    { value: 'cax31', label: 'CAX31 — 8 vCPU, 16 GB (ARM)', arch: 'arm' },
    { value: 'cax41', label: 'CAX41 — 16 vCPU, 32 GB (ARM)', arch: 'arm' },
    { value: 'cpx11', label: 'CPX11 — 2 vCPU, 2 GB (x86)', arch: 'x86' },
    { value: 'cpx21', label: 'CPX21 — 3 vCPU, 4 GB (x86)', arch: 'x86' },
    { value: 'cpx31', label: 'CPX31 — 4 vCPU, 8 GB (x86)', arch: 'x86' },
    { value: 'cpx41', label: 'CPX41 — 8 vCPU, 16 GB (x86)', arch: 'x86' },
    { value: 'cpx51', label: 'CPX51 — 16 vCPU, 32 GB (x86)', arch: 'x86' },
];

const hetznerLocations = [
    { value: 'fsn1', label: 'Falkenstein (fsn1)', arch: 'arm' },
    { value: 'nbg1', label: 'Nuremberg (nbg1)', arch: 'arm' },
    { value: 'hil', label: 'Hillsboro (hil)', arch: 'x86' },
];

const filteredHetznerLocations = computed(() => {
    const selectedType = hetznerServerTypes.find(
        (t) => t.value === form.server_type,
    );
    if (!selectedType) return hetznerLocations;
    return hetznerLocations.filter((l) => l.arch === selectedType.arch);
});

const doDropletSizes = [
    { value: 's-1vcpu-1gb', label: '1 vCPU, 1 GB' },
    { value: 's-1vcpu-2gb', label: '1 vCPU, 2 GB' },
    { value: 's-2vcpu-2gb', label: '2 vCPU, 2 GB' },
    { value: 's-2vcpu-4gb', label: '2 vCPU, 4 GB' },
    { value: 's-4vcpu-8gb', label: '4 vCPU, 8 GB' },
];

const doRegions = [
    { value: 'nyc1', label: 'New York 1 (nyc1)' },
    { value: 'nyc3', label: 'New York 3 (nyc3)' },
    { value: 'sfo3', label: 'San Francisco 3 (sfo3)' },
    { value: 'ams3', label: 'Amsterdam 3 (ams3)' },
    { value: 'sgp1', label: 'Singapore 1 (sgp1)' },
    { value: 'lon1', label: 'London 1 (lon1)' },
    { value: 'fra1', label: 'Frankfurt 1 (fra1)' },
    { value: 'blr1', label: 'Bangalore 1 (blr1)' },
];

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50';

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
                        <Badge
                            v-if="server.status === 'provisioned'"
                            variant="outline"
                            class="gap-1.5"
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
                        Create a server via a cloud provider or add your own
                        custom server.
                    </DialogDescription>
                </DialogHeader>

                <form
                    @submit.prevent="submitAddServer"
                    class="flex flex-col gap-4 py-2"
                >
                    <!-- Common fields -->
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
                        <label
                            class="text-sm font-medium"
                            for="server-hostname"
                            >Hostname</label
                        >
                        <Input
                            id="server-hostname"
                            v-model="form.hostname"
                            placeholder="e.g. production-01"
                            :disabled="form.processing"
                            @input="hostnameManuallyEdited = true"
                        />
                        <InputError :message="form.errors.hostname" />
                    </div>

                    <!-- Tabs -->
                    <Tabs v-model="activeTab">
                        <TabsList class="w-full">
                            <TabsTrigger value="hetzner" class="flex-1">
                                Hetzner
                            </TabsTrigger>
                            <TabsTrigger value="digital_ocean" class="flex-1">
                                Digital Ocean
                            </TabsTrigger>
                            <TabsTrigger value="custom" class="flex-1">
                                Custom
                            </TabsTrigger>
                        </TabsList>

                        <!-- Hetzner tab -->
                        <TabsContent
                            value="hetzner"
                            class="flex flex-col gap-4 pt-2"
                        >
                            <div
                                v-if="hetznerIntegrations.length === 0"
                                class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground"
                            >
                                <p>No Hetzner integrations configured.</p>
                                <Link
                                    :href="integrationsIndex.url()"
                                    class="mt-1 inline-block text-primary underline"
                                >
                                    Add one in Settings
                                </Link>
                            </div>
                            <template v-else>
                                <div class="flex flex-col gap-1.5">
                                    <label
                                        class="text-sm font-medium"
                                        for="hetzner-integration"
                                        >Integration</label
                                    >
                                    <select
                                        id="hetzner-integration"
                                        v-model.number="form.integration_id"
                                        :disabled="form.processing"
                                        :class="selectClass"
                                    >
                                        <option
                                            v-for="i in hetznerIntegrations"
                                            :key="i.id"
                                            :value="i.id"
                                        >
                                            {{ i.name }}
                                        </option>
                                    </select>
                                    <InputError
                                        :message="form.errors.integration_id"
                                    />
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div class="flex flex-col gap-1.5">
                                        <label
                                            class="text-sm font-medium"
                                            for="hetzner-type"
                                            >Server type</label
                                        >
                                        <select
                                            id="hetzner-type"
                                            v-model="form.server_type"
                                            :disabled="form.processing"
                                            :class="selectClass"
                                        >
                                            <option value="">
                                                Select type
                                            </option>
                                            <option
                                                v-for="t in hetznerServerTypes"
                                                :key="t.value"
                                                :value="t.value"
                                            >
                                                {{ t.label }}
                                            </option>
                                        </select>
                                        <InputError
                                            :message="form.errors.server_type"
                                        />
                                    </div>

                                    <div class="flex flex-col gap-1.5">
                                        <label
                                            class="text-sm font-medium"
                                            for="hetzner-location"
                                            >Location</label
                                        >
                                        <select
                                            id="hetzner-location"
                                            v-model="form.region"
                                            :disabled="form.processing"
                                            :class="selectClass"
                                        >
                                            <option value="">
                                                Select location
                                            </option>
                                            <option
                                                v-for="l in filteredHetznerLocations"
                                                :key="l.value"
                                                :value="l.value"
                                            >
                                                {{ l.label }}
                                            </option>
                                        </select>
                                        <InputError
                                            :message="form.errors.region"
                                        />
                                    </div>
                                </div>
                            </template>
                        </TabsContent>

                        <!-- Digital Ocean tab -->
                        <TabsContent
                            value="digital_ocean"
                            class="flex flex-col gap-4 pt-2"
                        >
                            <div
                                v-if="doIntegrations.length === 0"
                                class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground"
                            >
                                <p>
                                    No Digital Ocean integrations configured.
                                </p>
                                <Link
                                    :href="integrationsIndex.url()"
                                    class="mt-1 inline-block text-primary underline"
                                >
                                    Add one in Settings
                                </Link>
                            </div>
                            <template v-else>
                                <div class="flex flex-col gap-1.5">
                                    <label
                                        class="text-sm font-medium"
                                        for="do-integration"
                                        >Integration</label
                                    >
                                    <select
                                        id="do-integration"
                                        v-model.number="form.integration_id"
                                        :disabled="form.processing"
                                        :class="selectClass"
                                    >
                                        <option
                                            v-for="i in doIntegrations"
                                            :key="i.id"
                                            :value="i.id"
                                        >
                                            {{ i.name }}
                                        </option>
                                    </select>
                                    <InputError
                                        :message="form.errors.integration_id"
                                    />
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div class="flex flex-col gap-1.5">
                                        <label
                                            class="text-sm font-medium"
                                            for="do-size"
                                            >Droplet size</label
                                        >
                                        <select
                                            id="do-size"
                                            v-model="form.server_type"
                                            :disabled="form.processing"
                                            :class="selectClass"
                                        >
                                            <option value="">
                                                Select size
                                            </option>
                                            <option
                                                v-for="s in doDropletSizes"
                                                :key="s.value"
                                                :value="s.value"
                                            >
                                                {{ s.label }}
                                            </option>
                                        </select>
                                        <InputError
                                            :message="form.errors.server_type"
                                        />
                                    </div>

                                    <div class="flex flex-col gap-1.5">
                                        <label
                                            class="text-sm font-medium"
                                            for="do-region"
                                            >Region</label
                                        >
                                        <select
                                            id="do-region"
                                            v-model="form.region"
                                            :disabled="form.processing"
                                            :class="selectClass"
                                        >
                                            <option value="">
                                                Select region
                                            </option>
                                            <option
                                                v-for="r in doRegions"
                                                :key="r.value"
                                                :value="r.value"
                                            >
                                                {{ r.label }}
                                            </option>
                                        </select>
                                        <InputError
                                            :message="form.errors.region"
                                        />
                                    </div>
                                </div>
                            </template>
                        </TabsContent>

                        <!-- Custom tab -->
                        <TabsContent
                            value="custom"
                            class="flex flex-col gap-4 pt-2"
                        >
                            <div class="flex flex-col gap-1.5">
                                <label
                                    class="text-sm font-medium"
                                    for="server-ip"
                                >
                                    IP address
                                </label>
                                <Input
                                    id="server-ip"
                                    v-model="form.ip_address"
                                    placeholder="e.g. 65.21.100.42"
                                    :disabled="form.processing"
                                />
                                <InputError
                                    :message="form.errors.ip_address"
                                />
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
                                        :class="selectClass"
                                    >
                                        <option value="">
                                            Select provider
                                        </option>
                                        <option
                                            v-for="p in customProviders"
                                            :key="p.value"
                                            :value="p.value"
                                        >
                                            {{ p.label }}
                                        </option>
                                    </select>
                                    <InputError
                                        :message="form.errors.provider"
                                    />
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
                                    <InputError
                                        :message="form.errors.region"
                                    />
                                </div>
                            </div>
                        </TabsContent>
                    </Tabs>

                    <!-- Common bottom fields -->
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
                                :class="selectClass"
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
