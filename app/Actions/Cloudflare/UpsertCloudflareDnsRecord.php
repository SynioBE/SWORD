<?php

namespace App\Actions\Cloudflare;

use App\Services\Cloudflare\CloudflareService;

class UpsertCloudflareDnsRecord
{
    /**
     * Create or update DNS record(s) for the given name/content pair in the
     * specified zone.
     *
     * When $type is 'both', an A record and a CNAME record are upserted using
     * $content for the A record and $cnameContent for the CNAME record. If
     * $cnameContent is null, the CNAME is skipped.
     *
     * @return array<int, array<string, mixed>> The created/updated record(s).
     */
    public function handle(
        CloudflareService $service,
        string $zoneId,
        string $name,
        string $type,
        string $content,
        bool $proxied = false,
        int $ttl = 1,
        ?string $cnameContent = null,
    ): array {
        if ($type === 'both') {
            $results = [];
            $results[] = $this->upsertSingle($service, $zoneId, 'A', $name, $content, $proxied, $ttl);

            if ($cnameContent !== null) {
                $results[] = $this->upsertSingle($service, $zoneId, 'CNAME', $name, $cnameContent, $proxied, $ttl);
            }

            return $results;
        }

        return [$this->upsertSingle($service, $zoneId, $type, $name, $content, $proxied, $ttl)];
    }

    /**
     * Upsert a single DNS record by checking if a matching record already
     * exists (same type + name). Updates it if found, creates it otherwise.
     *
     * @return array<string, mixed>
     */
    private function upsertSingle(
        CloudflareService $service,
        string $zoneId,
        string $type,
        string $name,
        string $content,
        bool $proxied,
        int $ttl,
    ): array {
        $existingRecords = $service->getDnsRecords($zoneId);

        $normalizedName = strtolower(trim($name));
        $normalizedType = strtoupper($type);

        foreach ($existingRecords as $record) {
            if (
                strtoupper($record['type'] ?? '') === $normalizedType
                && strtolower($record['name'] ?? '') === $normalizedName
            ) {
                return $service->updateDnsRecord(
                    zoneId: $zoneId,
                    recordId: $record['id'],
                    type: $normalizedType,
                    name: $name,
                    content: $content,
                    proxied: $proxied,
                    ttl: $ttl,
                );
            }
        }

        return $service->createDnsRecord(
            zoneId: $zoneId,
            type: $normalizedType,
            name: $name,
            content: $content,
            proxied: $proxied,
            ttl: $ttl,
        );
    }
}
