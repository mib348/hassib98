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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('order_id')->index()->nullable();
            $table->integer('number')->index()->nullable();
            $table->string('location')->index()->nullable();
            $table->date('date')->index()->nullable();
            $table->string('day')->index()->nullable();
            $table->double('total_price')->nullable();
            $table->string('email')->nullable();
            $table->string('financial_status')->nullable();
            $table->string('fulfillment_status')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->string('gateway')->nullable();
            $table->text('note')->nullable();
            $table->longText('order_status_url')->nullable();
            $table->json('line_items')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
