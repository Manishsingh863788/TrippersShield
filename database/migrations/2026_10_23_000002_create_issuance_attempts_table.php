<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('idempotency_key', 150)->unique('uq_attempt_idempotency_key');
            $table->json('request_payload');
            $table->string('outcome', 50);
            $table->json('provider_response')->nullable();
            $table->string('error_category', 100)->nullable();
            $table->timestamps();

            $table->index(['policy_request_id', 'attempt_number'], 'idx_policy_attempt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
