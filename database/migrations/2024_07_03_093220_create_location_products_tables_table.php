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
        Schema::create('location_products_tables', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('product_id')->index();
            $table->string('location')->index()->nullable();
            $table->string('day')->index()->nullable();
            $table->integer('quantity')->nullable();
            $table->enum('inventory_type', ['immediate', 'preorder'])->default('immediate');
            $table->timestamp('created_at')->useCurrent()->nullable();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_products_tables');
    }
};
