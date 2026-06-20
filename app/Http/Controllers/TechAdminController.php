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
        $storeName = $this->resolveStoreNameByLocation((string) $location->name);
        $existingStatus = PiStatus::query()
            ->where('location_slug', $locationSlug)
            ->first();

        $previousLastSeenAt = $existingStatus?->last_seen_at instanceof CarbonInterface
            ? $existingStatus->last_seen_at->copy()
            : null;

        $published = MqttHelper::publishPiCheck((string) $location->name, [
            'event' => 'pi.check',
            'location' => (string) $location->name,
            'location_slug' => $locationSlug,
            'requested_at' => Carbon::now('Europe/Berlin')->toIso8601String(),
        ]);

        $latestStatus = $existingStatus;

        if ($published) {
            $latestStatus = $this->waitForUpdatedPiStatus($locationSlug, $previousLastSeenAt) ?? $existingStatus;
        }

        return response()->json([
            'message' => $published ? 'PI check requested.' : 'PI check publish failed.',
            'data' => [
                'location' => (string) $location->name,
                'location_slug' => $locationSlug,
                'published' => $published,
                'latest_row' => $this->buildStatusRow($location, $latestStatus, $storeName),
            ],
            'meta' => [
                'generated_at' => Carbon::now('Europe/Berlin')->toIso8601String(),
                'stale_after_seconds' => self::STALE_AFTER_SECONDS,
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
                $storeName = $storeNameByLocation->get($locationName);

                return $this->buildStatusRow($location, $status, $storeName);
            })
            ->values()
            ->all();
    }

    /**
     * Keep one row-shaping method for both the polling endpoint and the
     * manual-check endpoint so the table always receives the same fields.
     *
     * That avoids subtle drift where the first load and the on-demand refresh
     * would render different values for the same location.
     *
     * @return array<string, mixed>
     */
    private function buildStatusRow(Locations $location, ?PiStatus $status, ?string $storeName): array
    {
        $locationName = (string) $location->name;
        $locationSlug = MqttHelper::locationToTopicSlug($locationName);
        $payload = is_array($status?->payload) ? $status->payload : [];
        $resolvedPiStatus = $this->resolvePiStatus($status);
        $resolvedAppStatus = $this->resolveAppStatus($status);

        return [
            'location' => $locationName,
            'location_slug' => $locationSlug,
            'store' => $storeName,
            'client_id' => $status?->client_id,
            'pi_status' => $resolvedPiStatus,
            'app_status' => $resolvedAppStatus,
            'app_status_label' => $this->formatAppStatusLabel($resolvedAppStatus, $status?->app_version),
            'wifi_status' => $this->formatWifiStatus($payload['wifi_strength'] ?? null),
            'wifi_strength' => $payload['wifi_strength'] ?? null,
            'door_status' => $this->formatDoorStatus($payload['door_status'] ?? null),
            'online_since' => $this->resolveOnlineSince($status),
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
    }

    /**
     * The "store" label still comes from the existing DB mapping table rather
     * than MQTT, so the manual-check response needs a tiny lookup for the same
     * value used by the normal list endpoint.
     */
    private function resolveStoreNameByLocation(string $locationName): ?string
    {
        return StoreLocations::query()
            ->leftJoin('stores', 'stores.id', '=', 'store_locations.store_id')
            ->where('store_locations.location', $locationName)
            ->value('stores.name');
    }

    /**
     * The button should return the freshest subscriber-backed row it can get,
     * not just confirm that the publish call succeeded.
     *
     * We therefore poll the same PiStatus table the long-running subscriber
     * writes to and stop as soon as last_seen_at becomes newer than the row we
     * had before sending the check command.
     */
    private function waitForUpdatedPiStatus(
        string $locationSlug,
        ?CarbonInterface $previousLastSeenAt,
        int $timeoutMs = 12000,
        int $pollIntervalMs = 500
    ): ?PiStatus {
        $deadline = microtime(true) + ($timeoutMs / 1000);

        do {
            $status = PiStatus::query()
                ->where('location_slug', $locationSlug)
                ->first();

            if ($status !== null && $this->isFresherPiStatus($status, $previousLastSeenAt)) {
                return $status;
            }

            usleep($pollIntervalMs * 1000);
        } while (microtime(true) < $deadline);

        return PiStatus::query()
            ->where('location_slug', $locationSlug)
            ->first();
    }

    /**
     * The subscriber updates last_seen_at when Laravel actually receives the
     * heartbeat, so that column is the safest freshness signal for "live"
     * manual checks.
     */
    private function isFresherPiStatus(PiStatus $status, ?CarbonInterface $previousLastSeenAt): bool
    {
        if (! $status->last_seen_at instanceof CarbonInterface) {
            return false;
        }

        if ($previousLastSeenAt === null) {
            return true;
        }

        return $status->last_seen_at->greaterThan($previousLastSeenAt);
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

    /**
     * The client payload does not currently provide a separate "online since"
     * session-start field. For the live admin table we therefore use the most
     * recent Laravel-received heartbeat time first, because that is the value
     * that best matches the MQTT dashboard's "device is currently online" view.
     *
     * If Laravel has never recorded a last_seen_at, fall back to the Pi's own
     * timestamp so older rows still show some timing context instead of blank.
     */
    private function resolveOnlineSince(?PiStatus $status): ?string
    {
        if ($status === null) {
            return null;
        }

        if ($status->last_seen_at instanceof CarbonInterface) {
            return $this->formatDateTime($status->last_seen_at);
        }

        return $this->formatDateTime($status->heartbeat_at);
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

        return $value->format('Y-m-d H:i:s');
    }

    private function formatWifiStatus($wifiStrength): string
    {
        if ($wifiStrength === null || $wifiStrength === '') {
            return '-';
        }

        if (is_numeric($wifiStrength)) {
            return (string) $wifiStrength.' dBm';
        }

        return trim((string) $wifiStrength);
    }

    private function formatDoorStatus($doorStatus): string
    {
        $normalized = trim((string) ($doorStatus ?? ''));

        return $normalized !== '' ? $normalized : '-';
    }

    private function formatAppStatusLabel(string $appStatus, ?string $appVersion): string
    {
        $normalizedVersion = trim((string) ($appVersion ?? ''));

        if ($normalizedVersion === '') {
            return ucfirst($appStatus);
        }

        return ucfirst($appStatus).' ('.$normalizedVersion.')';
    }
}
