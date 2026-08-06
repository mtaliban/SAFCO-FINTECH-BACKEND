<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_factor_auths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('method', ['totp', 'sms', 'email'])->default('totp');
            $table->text('secret')->nullable(); // encrypted TOTP secret
            $table->json('recovery_codes')->nullable(); // encrypted
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'method']);
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()
                  ->constrained('users')->cascadeOnDelete();
            $table->string('identifier'); // email or phone
            $table->string('code', 10);
            $table->enum('type', ['registration', 'login', 'password_reset', '2fa', 'phone_verify', 'email_verify']);
            $table->enum('channel', ['sms', 'email']);
            $table->integer('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['identifier', 'type', 'verified_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('two_factor_auths');
    }
};
