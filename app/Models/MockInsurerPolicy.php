<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MockInsurerPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'issuance_reference',
        'policy_number',
        'provider_reference',
        'client_request_id',
        'agent_id',
        'customer_name',
        'plan_code',
        'destination',
        'sum_insured',
        'premium',
        'status',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'sum_insured' => 'integer',
            'premium' => 'decimal:2',
            'payload' => 'array',
        ];
    }
}
