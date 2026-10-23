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
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();
            $table->string('client_request_id', 100);
            $table->string('agent_id', 100);
            $table->string('customer_name', 255);
            $table->date('date_of_birth');
            $table->string('destination', 50);
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code', 50);
            $table->unsignedBigInteger('sum_insured');
            $table->unsignedInteger('travel_days');
            $table->decimal('premium', 12, 2);
            $table->string('status', 50)->default('RECEIVED');
            $table->string('provider_reference', 100)->nullable();
            $table->string('policy_number', 100)->nullable()->unique();
            $table->json('last_provider_response')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'client_request_id'], 'uq_agent_client_request');
            $table->index('status', 'idx_policy_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
