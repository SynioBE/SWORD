<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    HardDrive,
    Plus,
    ChevronRight,
    Loader2,
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
import {
    index as destinationsIndex,
    store as destinationsStore,
    show as destinationsShow,
    generateName as destinationsGenerateName,
} from '@/routes/backup-destinations';
import type { BreadcrumbItem } from '@/types';

interface DestinationRow {
    id: number;
    name: string;
    type: string;
    host: string;
    port: number;
    username: string;
    storage_path: string;
    status: string;
    last_connected_at: string | null;
    created_at: string;
}

const props = defineProps<{
    destinations: DestinationRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backup Destinations', href: destinationsIndex() },
];

const showAddModal = ref(false);

watch(showAddModal, async (open) => {
    if (!open) {
return;
}

    try {
        const response = await fetch(destinationsGenerateName.url());
        const data = await response.json();
        form.name = data.name;
    } catch {
        // silently ignore; user can type manually
    }
});

const form = useForm({
    name: '',
    type: 'borg',
    host: '',
    port: 22,
    username: '',
    auth_method: 'password',
    password: '',
    ssh_private_key: '',
    storage_path: '/backups',
});

function submitAdd() {
    form.post(destinationsStore(), {
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
</script>

<template>
    <Head title="Backup Destinations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Backup Destinations
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Manage where your server backups are stored.
                    </p>
                </div>
                <Button @click="showAddModal = true">
                    <Plus class="size-4" />
                    Add Destination
                </Button>
            </div>

            <!-- Empty state -->
            <div
                v-if="props.destinations.length === 0"
                class="flex flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-sidebar-border/70 py-20 dark:border-sidebar-border"
            >
                <div class="rounded-full bg-muted p-4">
                    <HardDrive class="size-8 text-muted-foreground" />
                </div>
                <div class="text-center">
                    <p class="font-medium">No backup destinations yet</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Add your first backup destination to get started.
                    </p>
                </div>
                <Button @click="showAddModal = true" variant="outline">
                    <Plus class="size-4" />
                    Add Destination
                </Button>
            </div>

            <!-- Destination list -->
            <div v-else class="flex flex-col gap-2">
                <Link
                    v-for="destination in props.destinations"
                    :key="destination.id"
                    :href="destinationsShow(destination.id)"
                    class="group flex items-center justify-between rounded-xl border border-sidebar-border/70 bg-card px-5 py-4 transition-colors hover:bg-accent dark:border-sidebar-border"
                >
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-muted p-2">
                            <HardDrive class="size-5 text-muted-foreground" />
                        </div>
                        <div>
                            <p class="leading-none font-medium">
                                {{ destination.name }}
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ destination.username }}@{{ destination.host }}:{{ destination.port }}
                                <span class="mx-1.5">·</span>
                                {{ destination.storage_path }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <Badge :variant="statusVariant(destination.status)" class="capitalize">
                            {{ destination.status }}
                        </Badge>
                        <ChevronRight
                            class="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                        />
                    </div>
                </Link>
            </div>
        </div>

        <!-- Add Destination Modal -->
        <Dialog v-model:open="showAddModal">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Add Backup Destination</DialogTitle>
                    <DialogDescription>
                        Configure a remote storage location for your backups.
                    </DialogDescription>
                </DialogHeader>

                <form
                    @submit.prevent="submitAdd"
                    class="flex flex-col gap-4 py-2"
                >
                    <div class="flex flex-col gap-1.5">
                        <label class="text-sm font-medium" for="dest-name"
                            >Name</label
                        >
                        <Input
                            id="dest-name"
                            v-model="form.name"
                            placeholder="e.g. Hetzner Storage Box"
                            :disabled="form.processing"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-sm font-medium" for="dest-host"
                                >Host</label
                            >
                            <Input
                                id="dest-host"
                                v-model="form.host"
                                placeholder="e.g. u123456.your-storagebox.de"
                                :disabled="form.processing"
                            />
                            <InputError :message="form.errors.host" />
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="text-sm font-medium" for="dest-port"
                                >Port</label
                            >
                            <Input
                                id="dest-port"
                                v-model.number="form.port"
                                type="number"
                                min="1"
                                max="65535"
                                :disabled="form.processing"
                            />
                            <InputError :message="form.errors.port" />
                        </div>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-sm font-medium" for="dest-username"
                            >Username</label
                        >
                        <Input
                            id="dest-username"
                            v-model="form.username"
                            placeholder="e.g. u123456"
                            :disabled="form.processing"
                        />
                        <InputError :message="form.errors.username" />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-sm font-medium" for="dest-auth-method"
                            >Authentication method</label
                        >
                        <select
                            id="dest-auth-method"
                            v-model="form.auth_method"
                            :disabled="form.processing"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <option value="password">Password</option>
                            <option value="ssh_key">SSH Key</option>
                        </select>
                        <InputError :message="form.errors.auth_method" />
                    </div>

                    <div v-if="form.auth_method === 'password'" class="flex flex-col gap-1.5">
                        <label class="text-sm font-medium" for="dest-password"
                            >Password</label
                        >
                        <Input
                            id="dest-password"
                            v-model="form.password"
                            type="password"
                            :disabled="form.processing"
                        />
                        <InputError :message="form.errors.password" />
                    </div>

                    <div v-if="form.auth_method === 'ssh_key'" class="flex flex-col gap-1.5">
                        <label class="text-sm font-medium" for="dest-ssh-key"
                            >SSH Private Key</label
                        >
                        <textarea
                            id="dest-ssh-key"
                            v-model="form.ssh_private_key"
                            :disabled="form.processing"
                            rows="4"
                            class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] placeholder:text-muted-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                        ></textarea>
                        <InputError :message="form.errors.ssh_private_key" />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-sm font-medium" for="dest-storage-path"
                            >Storage path</label
                        >
                        <Input
                            id="dest-storage-path"
                            v-model="form.storage_path"
                            placeholder="e.g. /backups"
                            :disabled="form.processing"
                        />
                        <InputError :message="form.errors.storage_path" />
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
                            Create Destination
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
