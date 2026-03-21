<?php

namespace App\Services\Cloud\DigitalOcean;

readonly class DigitalOceanDropletResult
{
    public function __construct(
        public int $dropletId,
        public string $name,
        public string $region,
        public string $type,
        public string $status,
        public ?string $publicIp,
        public int|string $sshKeyId,
        public ?string $sshKeyName,
        public string $sshKeyStatus,
    ) {}
}
