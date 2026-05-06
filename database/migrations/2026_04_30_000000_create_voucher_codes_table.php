<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store voucher codes that customers should see inside their account.
     *
     * source_line_key makes the orders/create webhook idempotent. If Shopify
     * retries the webhook, we can recognize the same order line and unit number
     * instead of issuing an extra gift card by accident.
     */
    public function up(): void
    {
        Schema::create('voucher_codes', function (Blueprint $table) {
            $table->id();
            $table->string('source_line_key')->unique();
            $table->string('shopify_gift_card_id')->nullable()->index();
            $table->unsignedBigInteger('shopify_order_id')->nullable()->index();
            $table->unsignedInteger('order_number')->nullable()->index();
            $table->string('shopify_customer_id')->nullable()->index();
            $table->string('customer_email')->nullable()->index();
            $table->unsignedBigInteger('line_item_id')->nullable()->index();
            $table->unsignedInteger('unit_index')->default(1);
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('product_title')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('variant_title')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->text('code')->nullable();
            $table->string('masked_code')->nullable();
            $table->string('status')->default('pending')->index();
            $table->string('source')->default('app_created')->index();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_codes');
    }
};
