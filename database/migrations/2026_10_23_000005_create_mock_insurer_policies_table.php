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
        Schema::create('mock_insurer_policies', function (Blueprint $table) {
            $table->id();
            $table->string('issuance_reference', 150)->unique('uq_mock_issuance_reference');
            $table->string('policy_number', 100)->unique('uq_mock_policy_number');
            $table->string('provider_reference', 100)->unique('uq_mock_provider_ref');
            $table->string('client_request_id', 100);
            $table->string('agent_id', 100);
            $table->string('customer_name', 255);
            $table->string('plan_code', 50);
            $table->string('destination', 50);
            $table->unsignedBigInteger('sum_insured');
            $table->decimal('premium', 12, 2);
            $table->string('status', 50)->default('ISSUED');
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mock_insurer_policies');
    }
};
