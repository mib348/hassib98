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
        Schema::create('fulfillments', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('order_id')->index()->unique();
            $table->integer('order')->index()->unique();
            $table->string('pick-up-date', 16)->nullable();
            $table->string('location')->index()->nullable();
            $table->string('status')->index()->nullable();
            $table->string('items-bought')->nullable();
            $table->string('right-items-removed')->nullable();
            $table->string('wrong-items-removed')->nullable();
            $table->string('time-of-pick-up', 32)->nullable();
            $table->string('door-open-time', 16)->nullable();
            $table->text('image-before')->nullable();
            $table->text('image-after')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('ip_address')->nullable();
            $table->longText('request_url')->nullable();
            $table->timestamp('created_at')->useCurrent()->nullable();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fulfillments');
    }
};
