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
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 150)->unique('uq_provider_event_id');
            $table->string('event_type', 100);
            $table->json('payload');
            $table->string('processing_status', 50)->default('PENDING');
            $table->timestamps();

            $table->index('processing_status', 'idx_event_processing_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
