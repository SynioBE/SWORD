<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, CloudCog, Settings } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as cloudflareIndex, zones as cloudflareZones } from '@/routes/cloudflare';
import { index as integrationsIndex } from '@/routes/integrations';
import type { BreadcrumbItem } from '@/types';

interface IntegrationRow {
    id: number;
    name: string;
    provider: string;
    created_at: string;
}

defineProps<{
    integrations: IntegrationRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Cloudflare', href: cloudflareIndex() },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Cloudflare" />

        <div class="px-4 py-6">
            <div class="flex items-center justify-between">
                <Heading
                    title="Cloudflare"
                    description="Select an account to manage its domains and DNS records"
                />
            </div>

            <!-- Empty state -->
            <div
                v-if="integrations.length === 0"
                class="mt-8 flex flex-col items-center justify-center rounded-lg border border-dashed px-6 py-16 text-center"
            >
                <CloudCog class="mb-4 h-10 w-10 text-muted-foreground" />
                <h2 class="text-lg font-semibold">
                    No Cloudflare accounts configured
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Add a Cloudflare integration in Settings to get started.
                </p>
                <Button as-child class="mt-6">
                    <Link :href="integrationsIndex()">
                        <Settings class="mr-2 h-4 w-4" />
                        Configure Integration
                    </Link>
                </Button>
            </div>

            <!-- Integrations table -->
            <div v-else class="mt-6">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Account name</TableHead>
                            <TableHead>Added</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="integration in integrations"
                            :key="integration.id"
                        >
                            <TableCell class="font-medium">
                                {{ integration.name }}
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{ new Date(integration.created_at).toLocaleDateString() }}
                            </TableCell>
                            <TableCell class="text-right">
                                <Button variant="ghost" size="sm" as-child>
                                    <Link :href="cloudflareZones({ integration: integration.id })">
                                        View zones
                                        <ChevronRight class="ml-1 h-4 w-4" />
                                    </Link>
                                </Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </div>
    </AppLayout>
</template>
