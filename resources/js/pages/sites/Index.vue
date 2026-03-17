<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    Globe,
    Plus,
    CheckCircle2,
    Clock,
    Loader2,
    XCircle,
    ChevronRight,
    Server,
} from 'lucide-vue-next';
import { ref } from 'vue';
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
    index as sitesIndex,
    store as sitesStore,
    show as sitesShow,
} from '@/routes/sites';
import type { BreadcrumbItem } from '@/types';

interface SiteServer {
    id: number;
    name: string;
    ip_address: string | null;
}

interface SiteRow {
    id: number;
    domain: string;
    php_version: string;
    status: string;
    current_step: string | null;
    installed_at: string | null;
    created_at: string;
    server: SiteServer;
}

interface ProvisionedServer {
    id: number;
    name: string;
    ip_address: string | null;
}

const props = defineProps<{
    sites: SiteRow[];
    servers: ProvisionedServer[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sites', href: sitesIndex() }];

const showAddModal = ref(false);

const form = useForm({
    server_id: '' as number | '',
    domain: '',
    php_version: '8.3',
});

const phpVersions = ['8.1', '8.2', '8.3', '8.4'];

function submitAddSite() {
    form.post(sitesStore(), {
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
            return 'Live';
        case 'installing':
            return 'Installing';
        case 'failed':
            return 'Failed';
        default:
            return 'Pending';
    }
}
</script>

<template>
    <Head title="Sites" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Sites</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Manage your WordPress sites.
                    </p>
                </div>
                <Button @click="showAddModal = true">
                    <Plus class="size-4" />
                    Add Site
                </Button>
            </div>

            <!-- Empty state -->
            <div
                v-if="props.sites.length === 0"
                class="flex flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-sidebar-border/70 py-20 dark:border-sidebar-border"
            >
                <div class="rounded-full bg-muted p-4">
                    <Globe class="size-8 text-muted-foreground" />
                </div>
                <div class="text-center">
                    <p class="font-medium">No sites yet</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Add your first WordPress site to get started.
                    </p>
                </div>
                <Button @click="showAddModal = true" variant="outline">
                    <Plus class="size-4" />
                    Add Site
                </Button>
            </div>

            <!-- Site list -->
            <div v-else class="flex flex-col gap-2">
                <Link
                    v-for="site in props.sites"
                    :key="site.id"
                    :href="sitesShow(site.id)"
                    class="group flex items-center justify-between rounded-xl border border-sidebar-border/70 bg-card px-5 py-4 transition-colors hover:bg-accent dark:border-sidebar-border"
                >
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-muted p-2">
                            <Globe class="size-5 text-muted-foreground" />
                        </div>
                        <div>
                            <p class="leading-none font-medium">
                                {{ site.domain }}
                            </p>
                            <p
                                class="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground"
                            >
                                <Server class="size-3.5" />
                                {{ site.server.name }}
                                <span class="mx-0.5">·</span>
                                PHP {{ site.php_version }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div
                            v-if="site.status === 'installing'"
                            class="flex items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <Loader2 class="size-3.5 animate-spin" />
                            <span>{{
                                site.current_step ?? 'Installing…'
                            }}</span>
                        </div>
                        <Badge :variant="statusVariant(site.status)">
                            <CheckCircle2
                                v-if="site.status === 'installed'"
                                class="size-3"
                            />
                            <Loader2
                                v-else-if="site.status === 'installing'"
                                class="size-3 animate-spin"
                            />
                            <XCircle
                                v-else-if="site.status === 'failed'"
                                class="size-3"
                            />
                            <Clock v-else class="size-3" />
                            {{ statusLabel(site.status) }}
                        </Badge>
                        <ChevronRight
                            class="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                        />
                    </div>
                </Link>
            </div>
        </div>

        <!-- Add Site Modal -->
        <Dialog v-model:open="showAddModal">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Add Site</DialogTitle>
                    <DialogDescription>
                        Choose a server and enter your domain. You'll get an
                        install script to run on the server.
                    </DialogDescription>
                </DialogHeader>

                <form
                    @submit.prevent="submitAddSite"
                    class="flex flex-col gap-4 py-2"
                >
                    <!-- No provisioned servers warning -->
                    <div
                        v-if="props.servers.length === 0"
                        class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800/40 dark:bg-amber-900/20 dark:text-amber-400"
                    >
                        You don't have any provisioned servers yet. Provision a
                        server first before adding a site.
                    </div>

                    <template v-else>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-sm font-medium" for="site-server"
                                >Server</label
                            >
                            <select
                                id="site-server"
                                v-model.number="form.server_id"
                                :disabled="form.processing"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option value="">Select server</option>
                                <option
                                    v-for="server in props.servers"
                                    :key="server.id"
                                    :value="server.id"
                                >
                                    {{ server.name }}
                                    <template v-if="server.ip_address">
                                        — {{ server.ip_address }}</template
                                    >
                                </option>
                            </select>
                            <InputError :message="form.errors.server_id" />
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="text-sm font-medium" for="site-domain"
                                >Domain</label
                            >
                            <Input
                                id="site-domain"
                                v-model="form.domain"
                                placeholder="e.g. example.com"
                                :disabled="form.processing"
                            />
                            <InputError :message="form.errors.domain" />
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="text-sm font-medium" for="site-php"
                                >PHP version</label
                            >
                            <select
                                id="site-php"
                                v-model="form.php_version"
                                :disabled="form.processing"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option
                                    v-for="v in phpVersions"
                                    :key="v"
                                    :value="v"
                                >
                                    PHP {{ v }}
                                </option>
                            </select>
                            <InputError :message="form.errors.php_version" />
                        </div>
                    </template>

                    <DialogFooter class="pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="showAddModal = false"
                            :disabled="form.processing"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            :disabled="
                                form.processing || props.servers.length === 0
                            "
                        >
                            <Loader2
                                v-if="form.processing"
                                class="size-4 animate-spin"
                            />
                            Create Site
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
