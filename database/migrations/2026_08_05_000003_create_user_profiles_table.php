<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('full_name');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('middle_name')->nullable();

            $table->enum('gender', ['male', 'female', 'other', 'prefer_not_to_say'])->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('nationality')->nullable();
            $table->string('national_id')->nullable();

            $table->string('position')->nullable();
            $table->string('department')->nullable();
            $table->text('bio')->nullable();

            $table->string('profile_picture')->nullable();
            $table->string('profile_picture_thumbnail')->nullable();
            $table->string('cover_photo')->nullable();

            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->default('Tanzania');

            $table->json('social_links')->nullable(); // linkedin, twitter, etc
            $table->json('preferences')->nullable(); // notification prefs, ui prefs
            $table->json('metadata')->nullable();

            $table->timestamp('profile_completed_at')->nullable();
            $table->integer('profile_completion_percentage')->default(0);

            $table->timestamps();

            $table->unique('user_id');
            $table->index('full_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
