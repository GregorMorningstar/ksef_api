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
            $table->string('ksef_id');
            $table->string('reference_number')->nullable();
            $table->string('number')->nullable();
            $table->date('issue_date')->nullable();
            $table->dateTime('invoicing_date')->nullable();
            $table->dateTime('acquisition_date')->nullable();
            $table->dateTime('permanent_storage_date')->nullable();
            $table->date('sale_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('payment_date')->nullable();
            $table->date('expected_payment_date')->nullable();
            $table->string('payment_status', 32)->default('oczekuje'); // oczekuje, zapłacona, nie zapłacona
            $table->string('buyer_nip', 20)->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_address')->nullable();
            $table->string('buyer_identifier_type', 32)->nullable();
            $table->string('buyer_identifier_value', 50)->nullable();
            $table->string('seller_nip', 20)->nullable();
            $table->string('seller_name')->nullable();
            $table->string('seller_address')->nullable();
            $table->decimal('total_gross', 15, 2)->nullable();
            $table->decimal('total_net', 15, 2)->nullable();
            $table->decimal('total_vat', 15, 2)->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('invoicing_mode', 32)->nullable();
            $table->string('invoice_type', 32)->nullable();
            $table->string('form_code_system_code', 20)->nullable();
            $table->string('form_code_schema_version', 20)->nullable();
            $table->string('form_code_value', 20)->nullable();
            $table->boolean('is_self_invoicing')->default(false);
            $table->boolean('has_attachment')->default(false);
            $table->string('invoice_hash')->nullable();
            $table->string('status', 32)->default('new');
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'ksef_id']);
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
