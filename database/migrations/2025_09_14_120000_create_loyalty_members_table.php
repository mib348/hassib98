<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for creating the loyalty_members table.
     * This table stores customer loyalty program information including points, tier, and purchase history.
     */
    public function up(): void
    {
        Schema::create('loyalty_members', function (Blueprint $table) {
            $table->id(); // Primary key for the loyalty member record
            $table->string('shopify_customer_id')->unique()->index(); // Shopify customer ID for linking with Shopify data
            $table->string('email')->unique()->index(); // Customer email address for identification
            $table->integer('points_balance')->default(0); // Current loyalty points balance
            $table->integer('lifetime_points')->default(0); // Total points earned throughout membership
            $table->enum('tier', ['bronze', 'silver', 'gold'])->default('bronze'); // Membership tier based on lifetime points
            $table->integer('items_purchased_count')->default(0); // Total count of items purchased for free item calculation
            $table->integer('free_items_pending')->default(0); // Number of free items customer is eligible for
            $table->enum('status', ['active', 'inactive'])->default('active'); // Account status
            $table->timestamps(); // Created and updated timestamps
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_members');
    }
};