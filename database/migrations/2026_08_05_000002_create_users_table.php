<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('username')->unique()->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('phone')->unique()->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->foreignId('organization_id')->nullable()
                  ->constrained('organizations')->nullOnDelete();

            $table->enum('status', ['active', 'inactive', 'suspended', 'pending'])
                  ->default('pending');

            $table->enum('auth_provider', ['email', 'phone', 'google', 'microsoft'])
                  ->default('email');
            $table->string('provider_id')->nullable();

            $table->boolean('two_factor_enabled')->default(false);
            $table->string('two_factor_method')->nullable(); // 'totp', 'sms', 'email'
            $table->timestamp('two_factor_verified_at')->nullable();

            $table->string('locale')->default('en');
            $table->string('timezone')->default('Africa/Dar_es_Salaam');

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->integer('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'organization_id']);
            $table->index('auth_provider');
            $table->index('email');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
