<?php

namespace App\Services\Cloud\Hetzner;

readonly class CreateHetznerServerData
{
    public function __construct(
        public string $apiKey,
        public string $name,
        public string $serverType,
        public string $location,
        public string $image,
        public string $publicKey,
        public int $publicIpPollAttempts = 30,
        public int $publicIpPollIntervalSeconds = 5,
    ) {}
}
