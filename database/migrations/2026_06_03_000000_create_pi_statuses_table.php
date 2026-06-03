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
        Schema::create('pi_statuses', function (Blueprint $table) {
            $table->id();

            // One row per physical RPi/location. New heartbeats update this row
            // instead of creating history, so dashboards can read the latest state fast.
            $table->string('location_slug')->unique();

            // The MQTT ClientId should normally be the same as location_slug.
            // It is nullable so last-will messages can still be stored if the
            // payload only contains status/message and the location is read from the topic.
            $table->string('client_id')->nullable()->index();

            // Expected values are online/offline, but this stays flexible so a
            // future Pi can send maintenance/error without another migration.
            $table->string('status', 64)->index();

            // heartbeat_at is the timestamp sent by the Pi. last_seen_at is when
            // Laravel processed the MQTT message, useful if device clocks drift.
            $table->dateTime('heartbeat_at')->nullable();
            $table->dateTime('last_seen_at')->nullable()->index();

            $table->unsignedBigInteger('uptime_seconds')->nullable();
            $table->string('app_version')->nullable();
            $table->text('message')->nullable();

            // Keep the raw JSON payload for troubleshooting without inventing a
            // separate history table. This row is still overwritten per location.
            $table->json('payload')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pi_statuses');
    }
};
