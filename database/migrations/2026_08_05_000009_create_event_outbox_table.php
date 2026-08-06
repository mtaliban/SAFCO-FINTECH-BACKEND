<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event Outbox Pattern - Guarantees at-least-once delivery to Message Broker
 * Events are stored here first, then a worker publishes to RabbitMQ/MQTT
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_outbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_name'); // e.g., user.registered
            $table->string('aggregate_type')->nullable(); // e.g., User
            $table->unsignedBigInteger('aggregate_id')->nullable();
            $table->json('payload');
            $table->string('routing_key')->nullable();
            $table->enum('broker', ['rabbitmq', 'mqtt', 'both'])->default('both');
            $table->enum('status', ['pending', 'published', 'failed'])->default('pending');
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('event_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_outbox');
    }
};
