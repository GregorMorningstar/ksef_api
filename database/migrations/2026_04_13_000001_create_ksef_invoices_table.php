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
        Schema::create('ksef_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ksef_id')->unique();
            $table->string('reference_number')->nullable();
            $table->string('number')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('sale_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('payment_date')->nullable();
            $table->date('expected_payment_date')->nullable();
            $table->string('payment_status', 32)->default('oczekuje'); // oczekuje, zapłacona, nie zapłacona
            $table->string('buyer_nip', 20)->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_address')->nullable();
            $table->string('seller_nip', 20)->nullable();
            $table->string('seller_name')->nullable();
            $table->string('seller_address')->nullable();
            $table->decimal('total_gross', 15, 2)->nullable();
            $table->decimal('total_net', 15, 2)->nullable();
            $table->decimal('total_vat', 15, 2)->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('status', 32)->default('new');
            $table->json('raw_json')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ksef_invoices');
    }
};
