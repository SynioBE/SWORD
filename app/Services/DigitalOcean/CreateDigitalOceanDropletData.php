<?php

namespace App\Services\DigitalOcean;

readonly class CreateDigitalOceanDropletData
{
    public function __construct(
        public string $apiKey,
        public string $name,
        public string $region,
        public string $size,
        public string $image,
        public string $publicKey,
        public int $publicIpPollAttempts = 30,
        public int $publicIpPollIntervalSeconds = 5,
    ) {}
}
