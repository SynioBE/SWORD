<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ChevronLeft, Pencil, Plus, RefreshCw, Shield, Globe, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Separator } from '@/components/ui/separator';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as cloudflareIndex, zones as cloudflareZones, purgeCache as cloudfarePurgeCache } from '@/routes/cloudflare';
import { store as dnsRecordsStore, update as dnsRecordsUpdate, destroy as dnsRecordsDestroy } from '@/routes/cloudflare/dns-records';
import type { BreadcrumbItem } from '@/types';

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

const showDnsModal = ref(false);

const dnsForm = useForm({
  name: '',
  type: 'A' as 'A' | 'CNAME' | 'both',
  content: '',
  cname_content: '',
  proxied: false,
  ttl: 1,
});

function openDnsModal(): void {
  dnsForm.reset();
  showDnsModal.value = true;
}

function submitDnsRecord(): void {
  dnsForm.post(
    dnsRecordsStore.url({ integration: props.integration.id, zone: props.zoneId }),
    {
      onSuccess: () => {
        showDnsModal.value = false;
        dnsForm.reset();
      },
    },
  );
}

function deleteDnsRecord(recordId: string): void {
  if (!confirm('Delete this DNS record? This cannot be undone.')) {
    return;
  }
  router.delete(
    dnsRecordsDestroy.url({ integration: props.integration.id, zone: props.zoneId, record: recordId }),
  );
}

const showEditModal = ref(false);
const editingRecordId = ref('');

const editForm = useForm({
  name: '',
  type: 'A' as 'A' | 'CNAME',
  content: '',
  proxied: false,
  ttl: 1,
});

function openEditModal(record: DnsRecord): void {
  editingRecordId.value = record.id;
  editForm.name = record.name;
  editForm.type = (record.type === 'CNAME' ? 'CNAME' : 'A') as 'A' | 'CNAME';
  editForm.content = record.content;
  editForm.proxied = record.proxied ?? false;
  editForm.ttl = typeof record.ttl === 'number' ? record.ttl : 1;
  showEditModal.value = true;
}

function submitEditRecord(): void {
  editForm.patch(
    dnsRecordsUpdate.url({ integration: props.integration.id, zone: props.zoneId, record: editingRecordId.value }),
    {
      onSuccess: () => {
        showEditModal.value = false;
      },
    },
  );
}

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
          <!-- <TabsTrigger value="analytics">
            <BarChart3 class="mr-2 h-4 w-4" />
            Analytics
          </TabsTrigger> -->
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
          <div class="mb-4 flex items-center justify-between">
            <p class="text-sm text-muted-foreground">
              {{ dnsRecords.length }} record{{ dnsRecords.length === 1 ? '' : 's' }}
            </p>
            <Button size="sm" @click="openDnsModal">
              <Plus class="mr-1.5 h-4 w-4" />
              Create DNS Record
            </Button>
          </div>

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
                <TableHead />
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
                <TableCell class="text-right">
                  <div class="flex items-center justify-end gap-1">
                    <Button variant="ghost" size="sm" @click="openEditModal(record)">
                      <Pencil class="h-4 w-4" />
                    </Button>
                    <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive"
                      @click="deleteDnsRecord(record.id)">
                      <Trash2 class="h-4 w-4" />
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </TabsContent>

        <!-- Analytics -->
        <!-- <TabsContent value="analytics" class="mt-6">
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
        </TabsContent> -->

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
    <!-- Edit DNS Record Modal -->
    <Dialog v-model:open="showEditModal">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Edit DNS Record</DialogTitle>
          <DialogDescription>
            Update the DNS record in <strong>{{ zoneName }}</strong>.
          </DialogDescription>
        </DialogHeader>

        <form class="flex flex-col gap-4 py-2" @submit.prevent="submitEditRecord">
          <!-- Type -->
          <div class="flex flex-col gap-1.5">
            <Label for="edit-dns-type">Type</Label>
            <Select v-model="editForm.type">
              <SelectTrigger id="edit-dns-type">
                <SelectValue placeholder="Select type" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="A">A — IPv4 address</SelectItem>
                <SelectItem value="CNAME">CNAME — Alias</SelectItem>
              </SelectContent>
            </Select>
            <InputError :message="editForm.errors.type" />
          </div>

          <!-- Name -->
          <div class="flex flex-col gap-1.5">
            <Label for="edit-dns-name">Name</Label>
            <Input id="edit-dns-name" v-model="editForm.name" autocomplete="off" />
            <InputError :message="editForm.errors.name" />
          </div>

          <!-- Content -->
          <div class="flex flex-col gap-1.5">
            <Label for="edit-dns-content">
              {{ editForm.type === 'CNAME' ? 'Target' : 'IPv4 Address' }}
            </Label>
            <Input id="edit-dns-content" v-model="editForm.content"
              :placeholder="editForm.type === 'CNAME' ? 'target.example.com' : '1.2.3.4'" autocomplete="off" />
            <InputError :message="editForm.errors.content" />
          </div>

          <!-- TTL -->
          <div class="flex flex-col gap-1.5">
            <Label for="edit-dns-ttl">TTL <span class="text-muted-foreground">(1 = Auto)</span></Label>
            <Input id="edit-dns-ttl" v-model.number="editForm.ttl" type="number" min="1" />
            <InputError :message="editForm.errors.ttl" />
          </div>

          <!-- Proxied -->
          <div class="flex items-center gap-2">
            <Checkbox id="edit-dns-proxied" v-model:checked="editForm.proxied" />
            <Label for="edit-dns-proxied">Proxy through Cloudflare</Label>
          </div>

          <DialogFooter class="pt-2">
            <Button type="button" variant="outline" @click="showEditModal = false">
              Cancel
            </Button>
            <Button type="submit" :disabled="editForm.processing">
              {{ editForm.processing ? 'Saving…' : 'Save Changes' }}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>

    <!-- Create DNS Record Modal -->
    <Dialog v-model:open="showDnsModal">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Create DNS Record</DialogTitle>
          <DialogDescription>
            Add a new DNS record to <strong>{{ zoneName }}</strong>. If a record
            with the same type and name already exists it will be updated.
          </DialogDescription>
        </DialogHeader>

        <form class="flex flex-col gap-4 py-2" @submit.prevent="submitDnsRecord">
          <!-- Type -->
          <div class="flex flex-col gap-1.5">
            <Label for="dns-type">Type</Label>
            <Select v-model="dnsForm.type">
              <SelectTrigger id="dns-type">
                <SelectValue placeholder="Select type" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="A">A — IPv4 address</SelectItem>
                <SelectItem value="CNAME">CNAME — Alias</SelectItem>
                <SelectItem value="both">Both (A + CNAME)</SelectItem>
              </SelectContent>
            </Select>
            <InputError :message="dnsForm.errors.type" />
          </div>

          <!-- Name -->
          <div class="flex flex-col gap-1.5">
            <Label for="dns-name">Name</Label>
            <Input id="dns-name" v-model="dnsForm.name" placeholder="subdomain or @ for root" autocomplete="off" />
            <InputError :message="dnsForm.errors.name" />
          </div>

          <!-- Content (A / both) -->
          <div class="flex flex-col gap-1.5">
            <Label for="dns-content">
              {{ dnsForm.type === 'CNAME' ? 'Target' : 'IPv4 Address' }}
            </Label>
            <Input id="dns-content" v-model="dnsForm.content"
              :placeholder="dnsForm.type === 'CNAME' ? 'target.example.com' : '1.2.3.4'" autocomplete="off" />
            <InputError :message="dnsForm.errors.content" />
          </div>

          <!-- CNAME content (only for 'both') -->
          <div v-if="dnsForm.type === 'both'" class="flex flex-col gap-1.5">
            <Label for="dns-cname-content">CNAME Target</Label>
            <Input id="dns-cname-content" v-model="dnsForm.cname_content" placeholder="target.example.com"
              autocomplete="off" />
            <InputError :message="dnsForm.errors.cname_content" />
          </div>

          <!-- TTL -->
          <div class="flex flex-col gap-1.5">
            <Label for="dns-ttl">TTL <span class="text-muted-foreground">(1 = Auto)</span></Label>
            <Input id="dns-ttl" v-model.number="dnsForm.ttl" type="number" min="1" placeholder="1" />
            <InputError :message="dnsForm.errors.ttl" />
          </div>

          <!-- Proxied -->
          <div class="flex items-center gap-2">
            <Checkbox id="dns-proxied" v-model:checked="dnsForm.proxied" />
            <Label for="dns-proxied">Proxy through Cloudflare</Label>
          </div>

          <DialogFooter class="pt-2">
            <Button type="button" variant="outline" @click="showDnsModal = false">
              Cancel
            </Button>
            <Button type="submit" :disabled="dnsForm.processing">
              {{ dnsForm.processing ? 'Saving…' : 'Save Record' }}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  </AppLayout>
</template>
