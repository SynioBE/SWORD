<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, Cloud } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
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
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import {
    destroy as integrationsDestroy,
    index as integrationsIndex,
    store as integrationsStore,
    update as integrationsUpdate,
} from '@/routes/integrations';
import type { BreadcrumbItem } from '@/types';

interface MaskedCredentials {
    type: 'api_token' | 'global_key';
    token: string | null;
    email: string | null;
    key: string | null;
}

interface IntegrationRow {
    id: number;
    name: string;
    provider: string;
    credentials: MaskedCredentials;
    created_at: string;
}

const PROVIDER_LABELS: Record<string, string> = {
    cloudflare: 'Cloudflare',
    digital_ocean: 'Digital Ocean',
    hetzner: 'Hetzner',
};

const PROVIDER_AUTH_TYPES: Record<string, string[]> = {
    cloudflare: ['api_token', 'global_key'],
    digital_ocean: ['api_token'],
    hetzner: ['api_token'],
};

defineProps<{
  integrations: IntegrationRow[];
}>();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Integrations', href: integrationsIndex() },
];

// ── Add ─────────────────────────────────────────────────────────────────────

const showAddDialog = ref(false);

const addForm = useForm({
    name: '',
    provider: 'cloudflare' as string,
    type: 'api_token' as 'api_token' | 'global_key',
    token: '',
    email: '',
    key: '',
});

watch(() => addForm.provider, (provider) => {
    const authTypes = PROVIDER_AUTH_TYPES[provider] ?? ['api_token'];
    if (!authTypes.includes(addForm.type)) {
        addForm.type = authTypes[0] as 'api_token' | 'global_key';
    }
});

const showAddAuthTypeSelect = computed(() => {
    const authTypes = PROVIDER_AUTH_TYPES[addForm.provider] ?? ['api_token'];
    return authTypes.length > 1;
});

function openAdd(): void {
    addForm.reset();
    showAddDialog.value = true;
}

function submitAdd(): void {
    addForm.post(integrationsStore.url(), {
        onSuccess: () => {
            showAddDialog.value = false;
        },
    });
}

// ── Edit ─────────────────────────────────────────────────────────────────────

const showEditDialog = ref(false);
const editingId = ref<number | null>(null);

const editForm = useForm({
    name: '',
    type: 'api_token' as 'api_token' | 'global_key',
    token: '',
    email: '',
    key: '',
});

function openEdit(integration: IntegrationRow): void {
    editingId.value = integration.id;
    editForm.name = integration.name;
    editForm.type = integration.credentials.type;
    editForm.token = '';
    editForm.email = integration.credentials.email ?? '';
    editForm.key = '';
    showEditDialog.value = true;
}

function submitEdit(): void {
    if (editingId.value === null) return;
    editForm.patch(integrationsUpdate.url({ integration: editingId.value }), {
        onSuccess: () => {
            showEditDialog.value = false;
        },
    });
}

// ── Delete ────────────────────────────────────────────────────────────────────

const showDeleteDialog = ref(false);
const deletingIntegration = ref<IntegrationRow | null>(null);
const deleteProcessing = ref(false);

function openDelete(integration: IntegrationRow): void {
    deletingIntegration.value = integration;
    showDeleteDialog.value = true;
}

function confirmDelete(): void {
    if (! deletingIntegration.value) return;
    deleteProcessing.value = true;
    useForm({}).delete(integrationsDestroy.url({ integration: deletingIntegration.value.id }), {
        onSuccess: () => {
            showDeleteDialog.value = false;
        },
        onFinish: () => {
            deleteProcessing.value = false;
        },
    });
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function providerLabel(provider: string): string {
    return PROVIDER_LABELS[provider] ?? provider;
}

function credentialSummary(creds: MaskedCredentials): string {
    if (creds.type === 'api_token') return 'API Token';
    return `Global Key · ${creds.email ?? ''}`;
}

const PROVIDER_TOKEN_PLACEHOLDERS: Record<string, string> = {
    cloudflare: 'Cloudflare API Token',
    digital_ocean: 'Digital Ocean API Token',
    hetzner: 'Hetzner API Token',
};

const addTokenPlaceholder = computed(() => PROVIDER_TOKEN_PLACEHOLDERS[addForm.provider] ?? 'API Token');

const credentialFieldsVisible = computed(() => ({
    token: addForm.type === 'api_token',
    emailKey: addForm.type === 'global_key',
}));

const editCredentialFieldsVisible = computed(() => ({
    token: editForm.type === 'api_token',
    emailKey: editForm.type === 'global_key',
}));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Integrations" />
        <h1 class="sr-only">Integrations</h1>

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <div class="flex items-start justify-between">
                    <Heading
                        variant="small"
                        title="Integrations"
                        description="Connect external services to enhance your workflow"
                    />
                    <Button size="sm" @click="openAdd">
                        <Plus class="mr-2 h-4 w-4" />
                        Add
                    </Button>
                </div>

                <!-- Empty state -->
                <div
                    v-if="integrations.length === 0"
                    class="flex flex-col items-center justify-center rounded-lg border border-dashed px-6 py-12 text-center"
                >
                    <Cloud class="mb-3 h-8 w-8 text-muted-foreground" />
                    <p class="text-sm font-medium">No integrations yet</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Add your first integration to connect external services.
                    </p>
                    <Button size="sm" class="mt-4" @click="openAdd">
                        <Plus class="mr-2 h-4 w-4" />
                        Add Integration
                    </Button>
                </div>

                <!-- Integrations table -->
                <Table v-else>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Provider</TableHead>
                            <TableHead>Auth</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="integration in integrations" :key="integration.id">
                            <TableCell class="font-medium">
                                {{ integration.name }}
                            </TableCell>
                            <TableCell>
                                <Badge variant="secondary">
                                    {{ providerLabel(integration.provider) }}
                                </Badge>
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{ credentialSummary(integration.credentials) }}
                            </TableCell>
                            <TableCell class="text-right">
                                <div class="flex justify-end gap-2">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        @click="openEdit(integration)"
                                    >
                                        <Pencil class="h-4 w-4" />
                                        <span class="sr-only">Edit</span>
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        @click="openDelete(integration)"
                                    >
                                        <Trash2 class="h-4 w-4 text-destructive" />
                                        <span class="sr-only">Delete</span>
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <!-- ── Add Dialog ─────────────────────────────────────────────── -->
            <Dialog v-model:open="showAddDialog">
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Add integration</DialogTitle>
                        <DialogDescription>
                            Connect an external service to your account.
                        </DialogDescription>
                    </DialogHeader>

                    <form class="space-y-4" @submit.prevent="submitAdd">
                        <div class="grid gap-2">
                            <Label for="add-name">Name</Label>
                            <Input
                                id="add-name"
                                v-model="addForm.name"
                                placeholder="e.g. Production CF account"
                                required
                            />
                            <InputError :message="addForm.errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="add-provider">Provider</Label>
                            <Select
                                v-model="addForm.provider"
                                name="provider"
                            >
                                <SelectTrigger id="add-provider">
                                    <SelectValue placeholder="Select provider" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="cloudflare">Cloudflare</SelectItem>
                                    <SelectItem value="digital_ocean">Digital Ocean</SelectItem>
                                    <SelectItem value="hetzner">Hetzner</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="addForm.errors.provider" />
                        </div>

                        <div v-if="showAddAuthTypeSelect" class="grid gap-2">
                            <Label for="add-type">Authentication type</Label>
                            <Select v-model="addForm.type" name="type">
                                <SelectTrigger id="add-type">
                                    <SelectValue placeholder="Select type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="api_token">API Token (recommended)</SelectItem>
                                    <SelectItem value="global_key">Global API Key</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="addForm.errors.type" />
                        </div>

                        <template v-if="credentialFieldsVisible.token">
                            <div class="grid gap-2">
                                <Label for="add-token">API Token</Label>
                                <Input
                                    id="add-token"
                                    v-model="addForm.token"
                                    type="password"
                                    :placeholder="addTokenPlaceholder"
                                    autocomplete="off"
                                />
                                <InputError :message="addForm.errors.token" />
                            </div>
                        </template>

                        <template v-if="credentialFieldsVisible.emailKey">
                            <div class="grid gap-2">
                                <Label for="add-email">Account email</Label>
                                <Input
                                    id="add-email"
                                    v-model="addForm.email"
                                    type="email"
                                    placeholder="you@example.com"
                                />
                                <InputError :message="addForm.errors.email" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="add-key">Global API Key</Label>
                                <Input
                                    id="add-key"
                                    v-model="addForm.key"
                                    type="password"
                                    placeholder="Global API Key"
                                    autocomplete="off"
                                />
                                <InputError :message="addForm.errors.key" />
                            </div>
                        </template>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                @click="showAddDialog = false"
                            >
                                Cancel
                            </Button>
                            <Button type="submit" :disabled="addForm.processing">
                                Save
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <!-- ── Edit Dialog ────────────────────────────────────────────── -->
            <Dialog v-model:open="showEditDialog">
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Edit integration</DialogTitle>
                        <DialogDescription>
                            Leave credential fields blank to keep existing values.
                        </DialogDescription>
                    </DialogHeader>

                    <form class="space-y-4" @submit.prevent="submitEdit">
                        <div class="grid gap-2">
                            <Label for="edit-name">Name</Label>
                            <Input
                                id="edit-name"
                                v-model="editForm.name"
                                placeholder="e.g. Production CF account"
                                required
                            />
                            <InputError :message="editForm.errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="edit-type">Authentication type</Label>
                            <Select v-model="editForm.type" name="type">
                                <SelectTrigger id="edit-type">
                                    <SelectValue placeholder="Select type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="api_token">API Token (recommended)</SelectItem>
                                    <SelectItem value="global_key">Global API Key</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="editForm.errors.type" />
                        </div>

                        <template v-if="editCredentialFieldsVisible.token">
                            <div class="grid gap-2">
                                <Label for="edit-token">API Token</Label>
                                <Input
                                    id="edit-token"
                                    v-model="editForm.token"
                                    type="password"
                                    placeholder="Leave blank to keep existing"
                                    autocomplete="off"
                                />
                                <InputError :message="editForm.errors.token" />
                            </div>
                        </template>

                        <template v-if="editCredentialFieldsVisible.emailKey">
                            <div class="grid gap-2">
                                <Label for="edit-email">Account email</Label>
                                <Input
                                    id="edit-email"
                                    v-model="editForm.email"
                                    type="email"
                                    placeholder="Leave blank to keep existing"
                                />
                                <InputError :message="editForm.errors.email" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="edit-key">Global API Key</Label>
                                <Input
                                    id="edit-key"
                                    v-model="editForm.key"
                                    type="password"
                                    placeholder="Leave blank to keep existing"
                                    autocomplete="off"
                                />
                                <InputError :message="editForm.errors.key" />
                            </div>
                        </template>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                @click="showEditDialog = false"
                            >
                                Cancel
                            </Button>
                            <Button type="submit" :disabled="editForm.processing">
                                Save
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <!-- ── Delete Dialog ──────────────────────────────────────────── -->
            <Dialog v-model:open="showDeleteDialog">
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Delete integration</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete
                            <span class="font-medium">{{ deletingIntegration?.name }}</span>?
                            This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            @click="showDeleteDialog = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            :disabled="deleteProcessing"
                            @click="confirmDelete"
                        >
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </SettingsLayout>
    </AppLayout>
</template>
