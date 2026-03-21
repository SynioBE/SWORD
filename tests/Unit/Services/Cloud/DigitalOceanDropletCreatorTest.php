<?php

use App\Services\Cloud\DigitalOcean\CreateDigitalOceanDropletData;
use App\Services\Cloud\DigitalOcean\DigitalOceanDropletCreator;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

test('DigitalOceanDropletCreator uploads a raw SSH key and returns droplet details', function () {
    $client = new class([new Response(201, [], json_encode(['ssh_key' => ['id' => 44, 'name' => 'uploaded-key']])), new Response(202, [], json_encode(['droplet' => ['id' => 999, 'name' => 'sword-web', 'region' => ['slug' => 'ams3'], 'size_slug' => 's-1vcpu-1gb', 'status' => 'new']])), new Response(200, [], json_encode(['droplet' => ['networks' => ['v4' => [['type' => 'public', 'ip_address' => '203.0.113.10']]]]]))]) implements ClientInterface
    {
        /** @var array<int, RequestInterface> */
        public array $requests = [];

        /**
         * @param  array<int, ResponseInterface>  $responses
         */
        public function __construct(private array $responses) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->requests[] = $request;

            return array_shift($this->responses);
        }
    };

    $creator = new DigitalOceanDropletCreator($client, new HttpFactory);

    $result = $creator->create(new CreateDigitalOceanDropletData(
        apiKey: 'test-token',
        name: 'sword-web',
        region: 'ams3',
        serverType: 's-1vcpu-1gb',
        image: 'ubuntu-24-04-x64',
        publicKey: 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIB-example uploaded-key',
        publicIpPollAttempts: 1,
        publicIpPollIntervalSeconds: 1,
    ));

    expect($result->dropletId)->toBe(999)
        ->and($result->name)->toBe('sword-web')
        ->and($result->region)->toBe('ams3')
        ->and($result->type)->toBe('s-1vcpu-1gb')
        ->and($result->status)->toBe('new')
        ->and($result->publicIp)->toBe('203.0.113.10')
        ->and($result->sshKeyId)->toBe(44)
        ->and($result->sshKeyName)->toBe('uploaded-key')
        ->and($result->sshKeyStatus)->toBe('uploaded');

    expect($client->requests)->toHaveCount(3)
        ->and($client->requests[0]->getMethod())->toBe('POST')
        ->and((string) $client->requests[0]->getUri())->toBe('https://api.digitalocean.com/v2/account/keys')
        ->and($client->requests[1]->getMethod())->toBe('POST')
        ->and((string) $client->requests[1]->getUri())->toBe('https://api.digitalocean.com/v2/droplets')
        ->and($client->requests[2]->getMethod())->toBe('GET')
        ->and((string) $client->requests[2]->getUri())->toBe('https://api.digitalocean.com/v2/droplets/999');

    expect(json_decode((string) $client->requests[1]->getBody(), true))
        ->toBe([
            'name' => 'sword-web',
            'region' => 'ams3',
            'size' => 's-1vcpu-1gb',
            'image' => 'ubuntu-24-04-x64',
            'ssh_keys' => [44],
        ]);
});

test('DigitalOceanDropletCreator resolves an existing key by name', function () {
    $client = new class([new Response(200, [], json_encode(['ssh_keys' => [['id' => 22, 'name' => 'existing-key', 'public_key' => 'ssh-ed25519 AAAA existing-key']]])), new Response(202, [], json_encode(['droplet' => ['id' => 123, 'name' => 'sword-app', 'status' => 'new']])), new Response(200, [], json_encode(['droplet' => ['networks' => ['v4' => [['type' => 'public', 'ip_address' => '198.51.100.7']]]]]))]) implements ClientInterface
    {
        /** @var array<int, RequestInterface> */
        public array $requests = [];

        /**
         * @param  array<int, ResponseInterface>  $responses
         */
        public function __construct(private array $responses) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->requests[] = $request;

            return array_shift($this->responses);
        }
    };

    $creator = new DigitalOceanDropletCreator($client, new HttpFactory);

    $result = $creator->create(new CreateDigitalOceanDropletData(
        apiKey: 'test-token',
        name: 'sword-app',
        region: 'nyc1',
        serverType: 's-1vcpu-2gb',
        image: 'ubuntu-24-04-x64',
        publicKey: 'existing-key',
        publicIpPollAttempts: 1,
        publicIpPollIntervalSeconds: 1,
    ));

    expect($result->dropletId)->toBe(123)
        ->and($result->publicIp)->toBe('198.51.100.7')
        ->and($result->sshKeyId)->toBe(22)
        ->and($result->sshKeyName)->toBe('existing-key')
        ->and($result->sshKeyStatus)->toBe('existing');

    expect($client->requests)->toHaveCount(3)
        ->and($client->requests[0]->getMethod())->toBe('GET')
        ->and((string) $client->requests[0]->getUri())->toBe('https://api.digitalocean.com/v2/account/keys');

    expect(json_decode((string) $client->requests[1]->getBody(), true))
        ->toMatchArray([
            'name' => 'sword-app',
            'region' => 'nyc1',
            'size' => 's-1vcpu-2gb',
            'image' => 'ubuntu-24-04-x64',
            'ssh_keys' => [22],
        ]);
});
