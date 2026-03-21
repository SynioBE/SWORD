<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ip_address' => $this->ip_address,
            'hostname' => $this->hostname,
            'provider' => $this->provider,
            'region' => $this->region,
            'timezone' => $this->timezone,
            'ssh_port' => $this->ssh_port,
            'ssh_public_key' => $this->ssh_public_key,
            'status' => $this->status,
            'current_step' => $this->current_step,
            'provision_log' => $this->provision_log ?? [],
            'provisioned_at' => $this->provisioned_at?->toIso8601String(),
            'is_online' => $this->is_online,
            'last_pinged_at' => $this->last_pinged_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
