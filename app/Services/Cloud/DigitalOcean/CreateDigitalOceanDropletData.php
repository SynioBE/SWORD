<?php

namespace App\Services\Cloud\DigitalOcean;

readonly class CreateDigitalOceanDropletData
{
    public function __construct(
        public string $apiKey,
        public string $name,
        public string $serverType,
        public string $region,
        public string $image,
        public string $publicKey,
        public int $publicIpPollAttempts = 30,
        public int $publicIpPollIntervalSeconds = 5,
    ) {}
}
