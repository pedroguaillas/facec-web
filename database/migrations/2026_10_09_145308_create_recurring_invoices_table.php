<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Facturas recurrentes: plantilla (`recurring_invoices` + items) que el
     * comando `invoices:recurring` emite cada período, y su historial
     * (`recurring_invoice_runs`, único por plantilla + período para no
     * facturar dos veces el mismo período).
     */
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('emision_point_id');
            $table->unsignedBigInteger('user_id'); // Creador: la emisión automática corre a su nombre.
            $table->string('name', 100);
            $table->string('frequency', 20); // App\StaticClasses\RecurringFrequencies
            $table->date('start_date'); // Ancla: las fechas de emisión se calculan desde aquí.
            $table->date('end_date')->nullable();
            $table->unsignedInteger('max_occurrences')->nullable();
            $table->date('next_run_date')->nullable();
            $table->string('status', 20)->default('ACTIVA'); // App\StaticClasses\RecurringInvoiceStatuses
            $table->boolean('auto_send')->default(true);
            $table->string('payment_type', 20)->default('CREDITO');
            $table->unsignedSmallInteger('due_days')->nullable();
            $table->unsignedTinyInteger('pay_method');
            $table->string('source_type', 40)->nullable(); // bank_account | cash_register
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 300)->nullable();
            $table->json('aditionals')->nullable();
            $table->unsignedInteger('emitted_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('emision_point_id')->references('id')->on('emision_points');
            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['status', 'next_run_date']);
        });

        Schema::create('recurring_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('recurring_invoice_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 14, 6);
            $table->decimal('price', 14, 6);
            $table->decimal('discount', 14, 2)->default(0);
            $table->unsignedTinyInteger('iva');
            $table->timestamps();

            $table->foreign('recurring_invoice_id')->references('id')->on('recurring_invoices')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products');
        });

        Schema::create('recurring_invoice_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('recurring_invoice_id');
            $table->date('period_date');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('status', 20); // App\StaticClasses\RecurringRunStatuses
            $table->string('message', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();

            $table->foreign('recurring_invoice_id')->references('id')->on('recurring_invoices')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders');
            $table->unique(['recurring_invoice_id', 'period_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoice_runs');
        Schema::dropIfExists('recurring_invoice_items');
        Schema::dropIfExists('recurring_invoices');
    }
};
