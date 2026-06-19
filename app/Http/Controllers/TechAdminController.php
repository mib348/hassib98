<?php

namespace App\Http\Controllers;

use App\Helpers\MqttHelper;
use App\Models\Locations;
use App\Models\PiStatus;
use App\Models\StoreLocations;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TechAdminController extends Controller
{
    /**
     * Pi heartbeats are expected every 10 seconds.
     * We allow a wider window before marking the row stale so minor network
     * jitter does not immediately make the admin table look offline.
     */
    private const STALE_AFTER_SECONDS = 30;

    /**
     * Render the embedded Shopify admin page shell.
     *
     * The table body is filled by the JSON polling endpoint so the initial
     * page stays small and follows the same admin-page pattern as other views.
     */
    public function index()
    {
        return view('tech_admin');
    }

    /**
     * Return the current status row for every active non-system location.
     *
     * This endpoint is intentionally simple JSON so the frontend can poll it
     * on a short interval without needing a browser-side MQTT connection.
     */
    public function statuses(): JsonResponse
    {
        return response()->json([
            'data' => $this->buildStatusRows(),
            'meta' => [
                'generated_at' => Carbon::now('Europe/Berlin')->toIso8601String(),
                'stale_after_seconds' => self::STALE_AFTER_SECONDS,
            ],
        ]);
    }

    /**
     * Publish one manual Pi check command for the requested location.
     *
     * The Pi should answer by publishing a fresh heartbeat on its normal
     * status topic. The page keeps polling that status topic through Laravel.
     */
    public function checkPi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location' => 'required|string|max:255',
        ]);

        $locationName = trim($validated['location']);

        $location = Locations::query()
            ->where('name', $locationName)
            ->where('is_active', 'Y')
            ->firstOrFail();

        $locationSlug = MqttHelper::locationToTopicSlug((string) $location->name);
        $published = MqttHelper::publishPiCheck((string) $location->name, [
            'event' => 'pi.check',
            'location' => (string) $location->name,
            'location_slug' => $locationSlug,
            'requested_at' => Carbon::now('Europe/Berlin')->toIso8601String(),
        ]);

        return response()->json([
            'message' => $published ? 'PI check requested.' : 'PI check publish failed.',
            'data' => [
                'location' => (string) $location->name,
                'location_slug' => $locationSlug,
                'published' => $published,
            ],
        ], $published ? 200 : 500);
    }

    /**
     * Shape the admin table rows from the current DB state.
     *
     * Location -> store comes from the existing store_locations mapping.
     * Location -> Pi row uses the same slug function already used for MQTT
     * topics so we do not invent a second matching convention.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildStatusRows(): array
    {
        $locations = Locations::query()
            ->where('is_active', 'Y')
            ->whereNotIn('name', ['Additional Inventory', 'Default Menu', 'Delivery'])
            ->orderByRaw('CASE WHEN location_order IS NULL THEN 1 ELSE 0 END')
            ->orderBy('location_order')
            ->orderBy('name')
            ->get();

        $storeNameByLocation = StoreLocations::query()
            ->leftJoin('stores', 'stores.id', '=', 'store_locations.store_id')
            ->select('store_locations.location', 'stores.name as store_name')
            ->get()
            ->pluck('store_name', 'location');

        $statusBySlug = PiStatus::query()
            ->get()
            ->keyBy('location_slug');

        return $locations
            ->map(function (Locations $location) use ($storeNameByLocation, $statusBySlug): array {
                $locationName = (string) $location->name;
                $locationSlug = MqttHelper::locationToTopicSlug($locationName);
                $status = $statusBySlug->get($locationSlug);
                $payload = is_array($status?->payload) ? $status->payload : [];
                $resolvedPiStatus = $this->resolvePiStatus($status);
                $resolvedAppStatus = $this->resolveAppStatus($status);

                return [
                    'location' => $locationName,
                    'location_slug' => $locationSlug,
                    'store' => $storeNameByLocation->get($locationName),
                    'client_id' => $status?->client_id,
                    'pi_status' => $resolvedPiStatus,
                    'app_status' => $resolvedAppStatus,
                    'wifi_status' => $this->formatWifiStatus($payload['wifi_strength'] ?? null),
                    'wifi_strength' => $payload['wifi_strength'] ?? null,
                    'door_status' => $payload['door_status'] ?? 'unknown',
                    'online_since' => $this->formatDateTime($status?->heartbeat_at),
                    'last_seen_at' => $this->formatDateTime($status?->last_seen_at),
                    'heartbeat_at' => $this->formatDateTime($status?->heartbeat_at),
                    'status_message' => $status?->message,
                    'app_version' => $status?->app_version,
                    'uptime_seconds' => $status?->uptime_seconds,
                    'ip_address' => $payload['ip_address'] ?? null,
                    'ram_usage' => $payload['ram_usage'] ?? null,
                    'disk_usage' => $payload['disk_usage'] ?? null,
                    'temperature' => $payload['temperature'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Treat outdated "online" rows as stale.
     * Explicit offline rows remain offline because those are the last-will
     * states already published by the broker.
     */
    private function resolvePiStatus(?PiStatus $status): string
    {
        if ($status === null) {
            return 'unknown';
        }

        $baseStatus = strtolower((string) $status->status);

        if ($baseStatus === 'online' && $this->isStale($status->last_seen_at)) {
            return 'stale';
        }

        return $baseStatus !== '' ? $baseStatus : 'unknown';
    }

    /**
     * The app-status column is a simplified health signal for operators.
     * For v1 it follows the heartbeat freshness because that is the only
     * continuous app-level signal the backend stores today.
     */
    private function resolveAppStatus(?PiStatus $status): string
    {
        if ($status === null) {
            return 'unknown';
        }

        $piStatus = $this->resolvePiStatus($status);

        if (in_array($piStatus, ['offline', 'stale'], true)) {
            return $piStatus;
        }

        return $status->app_version ? 'online' : 'unknown';
    }

    private function isStale($lastSeenAt): bool
    {
        if (!$lastSeenAt instanceof CarbonInterface) {
            return true;
        }

        $normalizedLastSeenAt = Carbon::createFromFormat(
            'Y-m-d H:i:s',
            $lastSeenAt->format('Y-m-d H:i:s'),
            'Europe/Berlin'
        );

        return $normalizedLastSeenAt->diffInSeconds(Carbon::now('Europe/Berlin')) > self::STALE_AFTER_SECONDS;
    }

    private function formatDateTime($value): ?string
    {
        if (!$value instanceof CarbonInterface) {
            return null;
        }

        return $value->copy()->timezone('Europe/Berlin')->format('Y-m-d H:i:s');
    }

    private function formatWifiStatus($wifiStrength): string
    {
        if (!is_numeric($wifiStrength)) {
            return 'unknown';
        }

        return (string) $wifiStrength;
    }
}
