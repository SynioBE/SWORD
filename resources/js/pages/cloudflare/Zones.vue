<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
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
import { index as cloudflareIndex, show as cloudflareShow } from '@/routes/cloudflare';
import type { BreadcrumbItem } from '@/types';

interface Integration {
  id: number;
  name: string;
}

interface Zone {
  id: string;
  name: string;
  status: string;
  plan?: { name: string };
  name_servers?: string[];
}

const props = defineProps<{
  integration: Integration;
  zones: Zone[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Cloudflare', href: cloudflareIndex() },
  { title: props.integration.name, href: '#' },
];

function zoneStatusVariant(status: string): 'default' | 'secondary' | 'destructive' {
  if (status === 'active') {
    return 'default';
  }

  if (status === 'pending') {
    return 'secondary';
  }


  return 'destructive';
}
</script>

<template>
  <AppLayout :breadcrumbs="breadcrumbs">

    <Head :title="`${integration.name} — Zones`" />

    <div class="px-4 py-6">
      <div class="mb-6 flex items-center gap-4">
        <Button variant="ghost" size="sm" as-child>
          <Link :href="cloudflareIndex()">
            <ChevronLeft class="h-4 w-4" />
            Back
          </Link>
        </Button>

        <Heading :title="integration.name" description="Manage domains and DNS records for this account" />
      </div>

      <div v-if="zones.length === 0" class="text-sm text-muted-foreground">
        No zones found in this Cloudflare account.
      </div>

      <Table v-else>
        <TableHeader>
          <TableRow>
            <TableHead>Domain</TableHead>
            <TableHead>Status</TableHead>
            <TableHead>Plan</TableHead>
            <TableHead>Name Servers</TableHead>
            <TableHead />
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="zone in zones" :key="zone.id">
            <TableCell class="font-medium">
              {{ zone.name }}
            </TableCell>
            <TableCell>
              <Badge :variant="zoneStatusVariant(zone.status)">
                {{ zone.status }}
              </Badge>
            </TableCell>
            <TableCell>
              {{ zone.plan?.name ?? '—' }}
            </TableCell>
            <TableCell class="text-sm text-muted-foreground">
              {{ zone.name_servers?.join(', ') ?? '—' }}
            </TableCell>
            <TableCell class="text-right">
              <Button variant="ghost" size="sm" as-child>
                <Link :href="cloudflareShow({ integration: integration.id, zone: zone.id })">
                  Manage
                </Link>
              </Button>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>
  </AppLayout>
</template>
