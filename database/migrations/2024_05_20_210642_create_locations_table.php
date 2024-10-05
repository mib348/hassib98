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
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index()->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->time('sameday_preorder_end_time')->nullable();
            $table->time('first_additional_inventory_end_time')->nullable();
            $table->time('second_additional_inventory_end_time')->nullable();
            $table->text('note')->nullable();
            $table->varchar('is_active', 1)->default('Y');
            $table->varchar('accept_only_preorders', 1)->default('N');
            $table->varchar('no_station', 1)->default('N');
            $table->varchar('additional_inventory', 1)->default('N');
            $table->timestamp('created_at')->useCurrent()->nullable();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
