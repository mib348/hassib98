<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for creating the loyalty_transactions table.
     * This table tracks all loyalty point transactions including earning, redemption, and adjustments.
     */
    public function up(): void
    {
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id(); // Primary key for the transaction record
            $table->foreignId('loyalty_member_id')->constrained(); // Foreign key to loyalty_members table
            $table->string('shopify_order_id')->nullable(); // Associated Shopify order ID (nullable for manual adjustments)
            $table->enum('type', ['earned', 'redeemed', 'adjusted']); // Type of transaction
            $table->integer('points'); // Number of points involved in the transaction (positive for earned, negative for redeemed)
            $table->text('description'); // Human-readable description of the transaction
            $table->json('metadata')->nullable(); // Additional metadata about the transaction (order details, etc.)
            $table->timestamps(); // Transaction timestamp
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};