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
        Schema::create('driver_fulfilled_status', function (Blueprint $table) {
            $table->id();
            $table->string('location')->index()->nullable();
            $table->date('date')->index()->nullable();
            $table->string('day')->index()->nullable();
            $table->text('image_name')->nullable();
            // $table->text('image_path')->nullable();
            $table->text('image_url')->nullable();
            // $table->string('added_by')->nullable();
            $table->timestamp('created_at')->useCurrent()->nullable();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_fulfilled_status');
    }
};
