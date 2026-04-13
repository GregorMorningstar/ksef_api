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
        Schema::create('ksef_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ksef_profile_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['offline', 'online'])->default('offline');
            $table->string('cert_path');
            $table->string('key_path');
            $table->string('cert_filename');
            $table->string('key_filename');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['ksef_profile_id', 'type', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ksef_certificates');
    }
};