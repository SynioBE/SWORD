<?php

namespace App\Services\Hetzner;

readonly class CreateHetznerServerData
{
    public function __construct(
        public string $apiKey,
        public string $name,
        public string $location,
        public string $serverType,
        public string $image,
        public string $publicKey,
        public bool $enableIpv4 = true,
        public bool $enableIpv6 = true,
        public int $publicIpPollAttempts = 30,
        public int $publicIpPollIntervalSeconds = 5,
    ) {}
}
