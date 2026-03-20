<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, RefreshCw, Shield, BarChart3, Globe } from 'lucide-vue-next';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as cloudflareIndex, zones as cloudflareZones, purgeCache as cloudfarePurgeCache } from '@/routes/cloudflare';
import type { BreadcrumbItem } from '@/types';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

interface DnsRecord {
  id: string;
  type: string;
  name: string;
  content: string;
  ttl: number | string;
  proxied?: boolean;
}

interface Analytics {
  totals?: {
    requests?: { all?: number; cached?: number; uncached?: number };
    bandwidth?: { all?: number; cached?: number; uncached?: number };
    threats?: { all?: number };
    pageviews?: { all?: number };
  };
}

interface SslSettings {
  value?: string;
  id?: string;
}

interface Integration {
  id: number;
  name: string;
}

const props = defineProps<{
  integration: Integration;
  zoneId: string;
  zoneName: string;
  dnsRecords: DnsRecord[];
  analytics: Analytics;
  sslSettings: SslSettings;
}>();

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Cloudflare', href: cloudflareIndex() },
  { title: props.integration.name, href: cloudflareZones({ integration: props.integration.id }) },
  { title: props.zoneName, href: '#' },
];

const purgingCache = ref(false);

function purgeCache(): void {
  purgingCache.value = true;
  router.post(
    cloudfarePurgeCache.url({ integration: props.integration.id, zone: props.zoneId }),
    {},
    {
      onFinish: () => {
        purgingCache.value = false;
      },
    },
  );
}

function formatBytes(bytes: number): string {
  if (bytes >= 1_073_741_824) {
    return (bytes / 1_073_741_824).toFixed(1) + ' GB';
  }

  if (bytes >= 1_048_576) {
    return (bytes / 1_048_576).toFixed(1) + ' MB';
  }

  if (bytes >= 1024) {
    return (bytes / 1024).toFixed(1) + ' KB';
  }

  return bytes + ' B';
}

function sslModeLabel(value: string | undefined): string {
  const map: Record<string, string> = {
    off: 'Off (not secure)',
    flexible: 'Flexible',
    full: 'Full',
    strict: 'Full (Strict)',
  };

  return value ? (map[value] ?? value) : 'Unknown';
}

function sslVariant(value: string | undefined): 'default' | 'secondary' | 'destructive' {
  if (value === 'strict' || value === 'full') {
    return 'default';
  }

  if (value === 'flexible') {
    return 'secondary';
  }

  return 'destructive';
}
</script>

<template>
  <AppLayout :breadcrumbs="breadcrumbs">

    <Head :title="`${zoneName} — ${integration.name}`" />

    <div class="px-4 py-6">
      <div class="mb-6 flex items-center gap-4">
        <Button variant="ghost" size="sm" as-child>
          <Link :href="cloudflareZones({ integration: integration.id })">
            <ChevronLeft class="h-4 w-4" />
            Back
          </Link>
        </Button>

        <Heading :title="zoneName" description="DNS records, analytics, and zone settings" />
      </div>

      <Tabs default-value="dns">
        <TabsList>
          <TabsTrigger value="dns">
            <Globe class="mr-2 h-4 w-4" />
            DNS Records
          </TabsTrigger>
          <TabsTrigger value="analytics">
            <BarChart3 class="mr-2 h-4 w-4" />
            Analytics
          </TabsTrigger>
          <TabsTrigger value="ssl">
            <Shield class="mr-2 h-4 w-4" />
            SSL / TLS
          </TabsTrigger>
          <TabsTrigger value="cache">
            <RefreshCw class="mr-2 h-4 w-4" />
            Cache
          </TabsTrigger>
        </TabsList>

        <!-- DNS Records -->
        <TabsContent value="dns" class="mt-6">
          <div v-if="dnsRecords.length === 0" class="text-sm text-muted-foreground">
            No DNS records found.
          </div>
          <Table v-else>
            <TableHeader>
              <TableRow>
                <TableHead>Type</TableHead>
                <TableHead>Name</TableHead>
                <TableHead>Content</TableHead>
                <TableHead>TTL</TableHead>
                <TableHead>Proxied</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="record in dnsRecords" :key="record.id">
                <TableCell>
                  <Badge variant="secondary">
                    {{ record.type }}
                  </Badge>
                </TableCell>
                <TableCell class="font-mono text-sm">
                  {{ record.name }}
                </TableCell>
                <TableCell class="max-w-xs truncate font-mono text-sm">
                  {{ record.content }}
                </TableCell>
                <TableCell class="text-muted-foreground">
                  {{ record.ttl === 1 ? 'Auto' : record.ttl + 's' }}
                </TableCell>
                <TableCell>
                  <Badge v-if="record.proxied !== undefined" :variant="record.proxied ? 'default' : 'secondary'">
                    {{ record.proxied ? 'Yes' : 'No' }}
                  </Badge>
                  <span v-else class="text-muted-foreground">—</span>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </TabsContent>

        <!-- Analytics -->
        <TabsContent value="analytics" class="mt-6">
          <div v-if="!analytics?.totals" class="text-sm text-muted-foreground">
            No analytics data available.
          </div>
          <div v-else class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <div class="rounded-lg border p-4">
              <p class="text-sm text-muted-foreground">
                Total Requests
              </p>
              <p class="mt-1 text-2xl font-semibold">
                {{
                  analytics.totals.requests?.all?.toLocaleString() ?? '—'
                }}
              </p>
              <p class="mt-1 text-xs text-muted-foreground">
                Cached:
                {{
                  analytics.totals.requests?.cached?.toLocaleString() ?? '—'
                }}
              </p>
            </div>

            <div class="rounded-lg border p-4">
              <p class="text-sm text-muted-foreground">
                Bandwidth
              </p>
              <p class="mt-1 text-2xl font-semibold">
                {{
                  analytics.totals.bandwidth?.all != null
                    ? formatBytes(analytics.totals.bandwidth.all)
                    : '—'
                }}
              </p>
              <p class="mt-1 text-xs text-muted-foreground">
                Cached:
                {{
                  analytics.totals.bandwidth?.cached != null
                    ? formatBytes(analytics.totals.bandwidth.cached)
                    : '—'
                }}
              </p>
            </div>

            <div class="rounded-lg border p-4">
              <p class="text-sm text-muted-foreground">
                Threats
              </p>
              <p class="mt-1 text-2xl font-semibold">
                {{
                  analytics.totals.threats?.all?.toLocaleString() ?? '—'
                }}
              </p>
            </div>

            <div class="rounded-lg border p-4">
              <p class="text-sm text-muted-foreground">
                Page Views
              </p>
              <p class="mt-1 text-2xl font-semibold">
                {{
                  analytics.totals.pageviews?.all?.toLocaleString() ?? '—'
                }}
              </p>
            </div>
          </div>

          <p class="mt-4 text-xs text-muted-foreground">
            Data shown for the last 7 days.
          </p>
        </TabsContent>

        <!-- SSL / TLS -->
        <TabsContent value="ssl" class="mt-6">
          <div class="max-w-sm rounded-lg border p-6">
            <div class="flex items-center justify-between">
              <div>
                <p class="font-medium">SSL Mode</p>
                <p class="mt-1 text-sm text-muted-foreground">
                  Current SSL/TLS encryption mode for this
                  zone.
                </p>
              </div>
              <Badge :variant="sslVariant(sslSettings?.value)" class="ml-4">
                {{ sslModeLabel(sslSettings?.value) }}
              </Badge>
            </div>

            <Separator class="my-4" />

            <p class="text-xs text-muted-foreground">
              To change the SSL mode, visit your Cloudflare
              dashboard → SSL/TLS → Overview.
            </p>
          </div>
        </TabsContent>

        <!-- Cache -->
        <TabsContent value="cache" class="mt-6">
          <div class="max-w-sm rounded-lg border p-6">
            <p class="font-medium">Purge Cache</p>
            <p class="mt-1 text-sm text-muted-foreground">
              Remove all cached content from Cloudflare's edge
              servers for this zone. All visitors will temporarily
              see uncached content until the cache is rebuilt.
            </p>

            <Button class="mt-4" variant="destructive" :disabled="purgingCache" @click="purgeCache">
              <RefreshCw :class="['mr-2 h-4 w-4', { 'animate-spin': purgingCache }]" />
              Purge Everything
            </Button>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  </AppLayout>
</template>
