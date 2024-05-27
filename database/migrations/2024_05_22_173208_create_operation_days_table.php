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
        Schema::create('operation_days', function (Blueprint $table) {
            $table->id();
            $table->string('location')->index()->nullable();
            $table->string('Monday', 1)->default('Y');
            $table->string('Tuesday', 1)->default('Y');
            $table->string('Wednesday', 1)->default('Y');
            $table->string('Thursday', 1)->default('Y');
            $table->string('Friday', 1)->default('Y');
            $table->string('Saturday', 1)->default('Y');
            $table->string('Sunday', 1)->default('Y');
            $table->timestamp('created_at')->useCurrent()->nullable();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operation_days');
    }
};
