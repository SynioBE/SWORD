<?php

namespace App\Services\SSH;

use App\Models\Server;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

class SSHService
{
    private ?SSH2 $connection = null;

    public function __construct(
        private readonly Server $server,
        private readonly int $timeout = 3600,
    ) {}

    public function connect(): void
    {
        $this->connection = new SSH2($this->server->ip_address, $this->server->ssh_port);
        $this->connection->setTimeout($this->timeout);

        $key = PublicKeyLoader::load($this->server->ssh_private_key);

        if (! $this->connection->login('sword', $key)) {
            throw new SSHConnectionException(
                "Failed to authenticate to {$this->server->ip_address}:{$this->server->ssh_port} as sword"
            );
        }
    }

    public function execute(string $command): SSHResult
    {
        if (! $this->connection) {
            $this->connect();
        }

        $this->connection->enableQuietMode();

        $output = $this->connection->exec($command);
        $stderr = $this->connection->getStdError();
        $exitCode = $this->connection->getExitStatus();

        return new SSHResult(
            output: $output ?: '',
            stderr: $stderr ?: '',
            exitCode: $exitCode !== false ? $exitCode : 1,
        );
    }

    public function disconnect(): void
    {
        if ($this->connection) {
            $this->connection->disconnect();
            $this->connection = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
