<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PolicyRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_request_id' => $this->client_request_id,
            'agent_id' => $this->agent_id,
            'customer_name' => $this->customer_name,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d') ?? (string) $this->date_of_birth,
            'destination' => $this->destination,
            'trip_start' => $this->trip_start?->format('Y-m-d') ?? (string) $this->trip_start,
            'trip_end' => $this->trip_end?->format('Y-m-d') ?? (string) $this->trip_end,
            'plan_code' => $this->plan_code,
            'sum_insured' => (int) $this->sum_insured,
            'travel_days' => (int) $this->travel_days,
            'premium' => (string) $this->premium,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : (string) $this->status,
            'provider_reference' => $this->provider_reference,
            'policy_number' => $this->policy_number,
            'attempt_count' => (int) $this->attempt_count,
            'last_provider_response' => $this->last_provider_response,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
