<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Migration: order_details_for_rpi table
|--------------------------------------------------------------------------
|
| Stores order details submitted by Raspberry Pi devices at store locations.
| RPIs POST pickup data (order info, counts, images, timestamps) via API,
| and admins can view all records in the Shopify admin panel.
|
*/

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_details_for_rpi', function (Blueprint $table) {
            $table->id();

            // Shopify order identifiers - indexed for fast lookups
            $table->integer('order_id')->index()->nullable();
            $table->integer('order_number')->index()->nullable();

            // How many items were in this order
            $table->integer('order_quantity')->nullable();

            // JSON snapshots of product counts before/after pickup
            // e.g. {"sushi_a": 5, "sushi_b": 3}
            $table->json('opening_count')->nullable();
            $table->json('closing_count')->nullable();

            // How long (seconds) the door was open during pickup
            $table->integer('open_time')->nullable();

            // Filenames of camera images captured before and after pickup
            $table->string('pre_img_name')->nullable();
            $table->string('post_img_name')->nullable();

            // When the RPI recorded this event & scheduled pickup time
            $table->datetime('current_date')->nullable();
            $table->datetime('pickup_date')->index()->nullable();

            // Which store location this pickup belongs to - indexed for filtering
            $table->string('order_location')->index()->nullable();

            // Flexible JSON field for any extra Shopify metafield data
            $table->json('metafields')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_details_for_rpi');
    }
};
