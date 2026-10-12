@php
    // Shopify adds one or more of these query values when the page is opened
    // inside Admin. A direct URL has none of them and must avoid App Bridge.
    $techAdminEmbedded = ! request()->attributes->get('master_frame') && ((int) request('menu') === 1
        || request()->filled('shop')
        || request()->filled('host')
        || request()->boolean('embedded'));
@endphp
@extends($techAdminEmbedded ? 'shopify-app::layouts.default' : 'layouts.app')

@section('styles')
<style>
    .tech-admin-status {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 84px;
        padding: 0.2rem 0.55rem;
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: capitalize;
        letter-spacing: 0;
    }

    .tech-admin-status.online {
        background-color: #d1e7dd;
        color: #0f5132;
    }

    .tech-admin-status.offline,
    .tech-admin-status.stale {
        background-color: #f8d7da;
        color: #842029;
    }

    .tech-admin-status.unknown {
        background-color: #e2e3e5;
        color: #41464b;
    }

    .tech-admin-subtext {
        color: #6c757d;
        font-size: 0.8rem;
        line-height: 1.2;
        margin-top: 0.25rem;
    }

    .tech-admin-meta {
        color: #6c757d;
        font-size: 0.9rem;
    }

    .tech-admin-actions {
        display: inline-flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    /* Standalone mode: when this page is opened by direct URL (outside the
       embedded Shopify admin) the App Bridge components — the <s-app-nav>
       sidebar and the <s-page> title bar with its "App pages" menu — have no
       admin chrome to render into, so they collapse into bare, duplicated link
       lists. Hide them whenever we are NOT inside the Shopify admin iframe; the
       actual page content (help, banner, table) is untouched. The marker class
       on <html> is set by the tiny inline script at the top of the content. */
    html.tech-admin-standalone s-app-nav,
    html.tech-admin-standalone s-page {
        display: none !important;
    }

    /* Plain heading used ONLY in standalone mode; embedded mode keeps the native
       <s-page heading> above instead, so this stays hidden there. */
    .tech-admin-standalone-heading {
        display: none;
    }

    html.tech-admin-standalone .tech-admin-standalone-heading {
        display: block;
    }

    /* Make the page's help tooltips easy to read: honour the line breaks in the
       tooltip text, left-align it, and allow a slightly wider box so the bulleted
       help renders as tidy lines instead of one tall run-on column. Scoped to this
       page because these styles only load with the Tech Admin view. */
    .tooltip-inner {
        white-space: pre-line;
        text-align: left;
        max-width: 320px;
    }
</style>
@endsection

@section('content')
{{-- Apply standalone styling before parsing the content. Master Admin also
     uses an iframe, so window.top alone cannot identify the Shopify layout. --}}
<script>
    if (@json(! $techAdminEmbedded) || window.self === window.top) {
        document.documentElement.classList.add('tech-admin-standalone');
    }
</script>

@if(! request()->attributes->get('master_frame'))
@include('partials.app_nav')

<s-page heading="Tech Admin">
    @include('partials.app_page_actions', ['primaryAction' => ['label' => 'Location Order Overview', 'path' => '/orders']])
</s-page>
@endif

{{-- Plain heading shown only in standalone mode (see the styles block); embedded
     mode uses the native <s-page heading="Tech Admin"> above. --}}
<h1 class="tech-admin-standalone-heading h4 px-2 pt-2 mb-0">Tech Admin</h1>

<div class="container-fluid p-2">
    <div class="admin-help-row">
        <span class="fw-semibold">Page help</span>
        @include('partials.admin_help_tooltip', ['text' => 'Monitors the latest Raspberry Pi status for each active location:
• Heartbeat and recent online state
• Ethernet / WiFi connection and internet speed
• CPU and vending-machine temperatures
• Lock and door-sensor state

Heartbeat: sent every 10 seconds
Internet strength: checked every 5 minutes'])
    </div>
    <div class="admin-help-row">
        <span class="fw-semibold">Pi status table</span>
        @include('partials.admin_help_tooltip', ['text' => 'Each row combines database location and store mapping with the most recent Pi heartbeat stored in Laravel. The door column prefers lock_status plus door_sensor_status from the Pi payload and falls back to the older legacy door_status when needed.'])
    </div>
    <div class="d-flex justify-content-end mb-2">
        <span class="tech-admin-meta" id="tech-admin-last-updated">Last update: waiting for first refresh</span>
    </div>
    <div class="admin-help-row">
        <span class="fw-semibold">Device commands</span>
        @include('partials.admin_help_tooltip', ['text' => 'Check PI Response sends the lightweight health check. Test Internet requests one manual speed test. Open Door and Close Door request the corresponding action from the Pi. The Pi must support these commands. Restart Device requests a reboot. Only the selected location is busy during an individual request; other locations remain usable. Bulk actions wait until individual requests finish. Command sent and fresh status received describe dispatch and heartbeat activity only; they do not confirm that a door opened, closed, or locked, or that a restart completed. Individual results remain in each command cell until the next action or page reload.'])
    </div>
    <div class="d-flex flex-wrap justify-content-end align-items-center gap-2 mb-2">
        @include('partials.admin_help_tooltip', ['text' => 'Open All Doors, Close All Doors and Restart All Devices target all active locations in this table, including offline or unknown devices. Additional Inventory, Default Menu and Delivery are excluded. One confirmation shows the action and current location count; the server resolves the targets when the request arrives. All commands are sent before one shared status wait of up to 12 seconds. Requests are never retried automatically.'])
        <span class="tech-admin-meta" id="tech-admin-target-count">Waiting for locations</span>
        <button type="button" class="btn btn-sm btn-primary js-tech-bulk-command" data-command="open_door" data-label="Open All Doors" disabled>Open All Doors</button>
        <button type="button" class="btn btn-sm btn-primary js-tech-bulk-command" data-command="close_door" data-label="Close All Doors" disabled>Close All Doors</button>
        <button type="button" class="btn btn-sm btn-danger js-tech-bulk-command" data-command="restart_device" data-label="Restart All Devices" disabled>Restart All Devices</button>
    </div>
    {{-- Command feedback belongs in temporary top-right toasts rather than a
         permanent alert that pushes the large status table down. Each action
         creates its own toast, so a pending message and its result can stack. --}}
    <div id="tech-admin-toast-container" class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1090;" aria-live="polite" aria-atomic="true"></div>
    <div class="table-responsive">
        <table class="table table-bordered table-striped table-hover table-vcenter" id="techAdminTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Location</th>
                    <th>Store</th>
                    <th>Client ID</th>
                    <th>PI Status</th>
                    <th>App Status</th>
                    <th>Network</th>
                    <th>CPU Temp</th>
                    <th>Vending Temp</th>
                    <th>Download</th>
                    <th>Upload</th>
                    <th>Door Status</th>
                    <th>Online Since</th>
                    <th>Device Commands</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="14" class="text-center text-muted">Loading Pi status rows...</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
@endsection

@section('scripts')
    @if (! $techAdminEmbedded)
        {{-- The plain Laravel layout does not load jQuery, while this existing
             page uses jQuery for polling and command requests. --}}
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    @endif
    @parent
    @include('partials.app_navigation')

    <script type="text/javascript">
        $(function () {
            const statusesUrl = @json(route('tech_admin.statuses'));
            const checkUrl = @json(route('tech_admin.check_pi'));
            const commandUrl = @json(route('tech_admin.command'));
            const commandAllUrl = @json(route('tech_admin.command_all'));
            const pollingIntervalMs = 15000;
            let isLoadingStatuses = false;
            let statusRows = [];
            let statusesReady = false;
            let isDispatching = false;
            // Polling rebuilds the table, so pending/result state must live outside
            // the DOM. Individual requests only lock their own location, allowing
            // other rows to work while a slow or offline Pi uses its response window.
            // The bulk flag still locks every row because it targets every device.
            const pendingLocations = new Set();
            const commandStates = new Map();

            function escapeHtml(value) {
                return $('<div>').text(value ?? '').html();
            }

            function escapeHtmlAttribute(value) {
                return escapeHtml(value).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            function renderStatusBadge(value) {
                const normalized = (value || 'unknown').toString().toLowerCase();
                const label = normalized.charAt(0).toUpperCase() + normalized.slice(1);

                return '<span class="tech-admin-status ' + escapeHtml(normalized) + '">' + escapeHtml(label) + '</span>';
            }

            function renderAppStatusCell(row) {
                const versionText = row.app_version ? '<div class="tech-admin-subtext">' + escapeHtml(row.app_version) + '</div>' : '';

                return renderStatusBadge(row.app_status) + versionText;
            }

            function formatMetric(value, unit) {
                if (value === null || value === undefined || value === '') {
                    return '-';
                }

                const text = String(value).trim();

                return /^-?\d+(\.\d+)?$/.test(text) ? text + ' ' + unit : text;
            }

            function renderRow(row, indexLabel) {
                const locationAttribute = escapeHtmlAttribute(row.location);
                const commandButtons = [
                    '<div class="tech-admin-actions">',
                        '<button type="button" class="btn btn-sm btn-outline-secondary js-tech-device-action js-tech-check-pi" data-label="Check PI Response" data-location="' + locationAttribute + '" title="Request a lightweight status response from this device">',
                            'Check PI Response',
                        '</button>',
                        '<button type="button" class="btn btn-sm btn-outline-primary js-tech-device-action js-tech-device-command" data-label="Test Internet" data-command="test_internet_connection" data-location="' + locationAttribute + '" title="Run one manual internet speed test on this device">',
                            'Test Internet',
                        '</button>',
                        '<button type="button" class="btn btn-sm btn-outline-danger js-tech-device-action js-tech-device-command" data-label="Restart Device" data-command="restart_device" data-location="' + locationAttribute + '" title="Restart this device">',
                            'Restart Device',
                        '</button>',
                        '<button type="button" class="btn btn-sm btn-outline-primary js-tech-device-action js-tech-device-command" data-label="Open Door" data-command="open_door" data-location="' + locationAttribute + '" title="Request this device to open the door">Open Door</button>',
                        '<button type="button" class="btn btn-sm btn-outline-primary js-tech-device-action js-tech-device-command" data-label="Close Door" data-command="close_door" data-location="' + locationAttribute + '" title="Request this device to close the door">Close Door</button>',
                    '</div>',
                    '<div class="tech-admin-command-result small mt-1" role="status">' + escapeHtml(commandStates.get(row.location) || '') + '</div>'
                ].join('');

                return [
                    '<tr data-location-slug="' + escapeHtml(row.location_slug || '') + '">',
                        '<td>' + escapeHtml(indexLabel || '-') + '</td>',
                        '<td>' + escapeHtml(row.location || '-') + '</td>',
                        '<td>' + escapeHtml(row.store || '-') + '</td>',
                        '<td>' + escapeHtml(row.client_id || '-') + '</td>',
                        '<td>' + renderStatusBadge(row.pi_status) + '</td>',
                        '<td>' + renderAppStatusCell(row) + '</td>',
                        '<td>' + escapeHtml(row.network_status || '-') + '</td>',
                        '<td>' + escapeHtml(formatMetric(row.cpu_temp, 'C')) + '</td>',
                        '<td>' + escapeHtml(formatMetric(row.temperature, 'C')) + '</td>',
                        '<td>' + escapeHtml(formatMetric(row.download_mbps, 'Mbps')) + '</td>',
                        '<td>' + escapeHtml(formatMetric(row.upload_mbps, 'Mbps')) + '</td>',
                        '<td>' + escapeHtml(row.door_status || '-') + '</td>',
                        '<td>' + escapeHtml(row.online_since || '-') + '</td>',
                        '<td>' + commandButtons + '</td>',
                    '</tr>'
                ].join('');
            }

            function renderRows(rows) {
                if (!rows.length) {
                    return '<tr><td colspan="14" class="text-center text-muted">No active locations found.</td></tr>';
                }

                return rows.map(function (row, index) {
                    return renderRow(row, (index + 1) + '.');
                }).join('');
            }

            function renderCurrentRows() {
                $('#techAdminTable tbody').html(renderRows(statusRows));
                // Newly rendered buttons must inherit the in-flight request lock.
                $('.js-tech-device-action').each(function () {
                    $(this).prop('disabled', isDispatching || pendingLocations.has($(this).data('location')));
                });
                $('.js-tech-bulk-command').prop('disabled', isDispatching || pendingLocations.size > 0 || !statusesReady || !statusRows.length);
                $('#tech-admin-target-count').text(statusRows.length + ' locations');
            }

            function replaceOrAppendRow(row) {
                if (!row || !row.location_slug) {
                    return false;
                }
                const index = statusRows.findIndex(function (existing) {
                    return existing.location_slug === row.location_slug;
                });
                if (index >= 0) {
                    statusRows[index] = row;
                } else {
                    statusRows.push(row);
                }
                return true;
            }

            function updateLastUpdatedLabel(isoValue) {
                if (!isoValue) {
                    return;
                }

                $('#tech-admin-last-updated').text('Last update: ' + isoValue);
            }

            // Create a temporary Bootstrap toast for every command state. Both
            // Tech Admin layouts load Bootstrap's JavaScript, so this works in
            // Shopify embedded mode and when the page is opened directly.
            function showToast(message, type) {
                const container = document.getElementById('tech-admin-toast-container');

                if (!container || !window.bootstrap || !window.bootstrap.Toast) {
                    return;
                }

                const toastElement = document.createElement('div');
                toastElement.className = 'toast align-items-center text-bg-' + type + ' border-0';
                toastElement.setAttribute('role', 'alert');
                toastElement.setAttribute('aria-live', 'assertive');
                toastElement.setAttribute('aria-atomic', 'true');

                // Warning and info backgrounds use dark text, so their close
                // buttons must also use Bootstrap's dark default icon.
                const darkCloseButton = type === 'warning' || type === 'info';
                const closeButtonClass = darkCloseButton ? 'btn-close' : 'btn-close btn-close-white';

                toastElement.innerHTML =
                    '<div class="d-flex">' +
                        '<div class="toast-body"></div>' +
                        '<button type="button" class="' + closeButtonClass + ' me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
                    '</div>';

                // textContent prevents a location or error message from being
                // interpreted as HTML inside the notification.
                toastElement.querySelector('.toast-body').textContent = message;
                container.appendChild(toastElement);

                const toast = new window.bootstrap.Toast(toastElement, {
                    autohide: true,
                    delay: 6000
                });

                toastElement.addEventListener('hidden.bs.toast', function () {
                    toastElement.remove();
                });

                toast.show();
            }

            function loadStatuses() {
                if (isLoadingStatuses) {
                    return;
                }

                isLoadingStatuses = true;

                $.ajax({
                    url: statusesUrl,
                    type: 'GET',
                    dataType: 'json',
                    success: function (response) {
                        statusRows = Array.isArray(response.data) ? response.data : [];
                        statusesReady = true;
                        renderCurrentRows();
                        updateLastUpdatedLabel(response.meta ? response.meta.generated_at : null);
                        initializeAdminHelpTooltips();
                    },
                    error: function (xhr) {
                        const errorMessage = window.getAjaxErrorMessage(xhr, 'Unable to load Pi status rows.');
                        // Keep command feedback and pending controls visible if a poll
                        // fails. A failed refresh must never unlock a running request.
                        statusesReady = false;
                        renderCurrentRows();
                        $('#tech-admin-last-updated').text(errorMessage);
                    },
                    complete: function () {
                        isLoadingStatuses = false;
                    }
                });
            }

            function recordCommandResult(data, actionLabel) {
                const detail = data.published
                    ? (data.pi_replied ? 'Command sent; fresh status received.' : 'Command sent; no fresh status received within ~12s.')
                    : 'Publish failed; delivery not confirmed.';
                commandStates.set(data.location, actionLabel + ': ' + detail);
                replaceOrAppendRow(data.latest_row || null);
            }

            function sendDeviceAction($button, url, requestData, actionLabel, pendingLabel) {
                const isBulk = url === commandAllUrl;
                if (isDispatching || (isBulk ? pendingLocations.size > 0 : pendingLocations.has(requestData.location))) {
                    return;
                }
                const targets = isBulk ? statusRows.map(row => row.location) : [requestData.location];
                if (isBulk) {
                    isDispatching = true;
                } else {
                    pendingLocations.add(requestData.location);
                }
                targets.forEach(location => commandStates.set(location, actionLabel + ': ' + pendingLabel));
                renderCurrentRows();
                showToast('Sending ' + actionLabel + (isBulk ? ' to ' + targets.length + ' locations...' : ' to ' + requestData.location + '...'), 'info');

                function handleResponse(response) {
                    const results = isBulk ? response.data : [response.data];
                    results.forEach(data => recordCommandResult(data, actionLabel));
                    updateLastUpdatedLabel(response.meta ? response.meta.generated_at : null);
                    if (isBulk) {
                        const meta = response.meta;
                        showToast(actionLabel + ': ' + meta.sent + '/' + meta.total + ' commands sent; ' + meta.failed + ' failed; fresh status received from ' + meta.responded + '.',
                            meta.sent === 0 ? 'danger' : (meta.failed || meta.responded < meta.sent ? 'warning' : 'success'));
                    } else {
                        const data = response.data;
                        showToast(requestData.location + ' — ' + commandStates.get(requestData.location),
                            !data.published ? 'danger' : (data.pi_replied ? 'success' : 'warning'));
                    }
                }

                $.ajax({
                    url: url,
                    type: 'POST',
                    dataType: 'json',
                    data: requestData,
                    success: handleResponse,
                    error: function (xhr) {
                        const response = xhr.responseJSON;
                        // HTTP 500 can contain a complete set of failed publishes.
                        // Transport failures are uncertain: never claim nothing was
                        // sent, and never retry a door/restart command automatically.
                        if (response && (isBulk ? Array.isArray(response.data) : response.data && response.data.location)) {
                            handleResponse(response);
                            return;
                        }
                        const message = xhr.status === 422
                            ? window.getAjaxErrorMessage(xhr, 'Request rejected.')
                            : 'Delivery unknown. Check device status before sending again; this request was not retried.';
                        targets.forEach(location => commandStates.set(location, actionLabel + ': ' + message));
                        showToast(message, 'danger');
                    },
                    complete: function () {
                        // Finishing one row must not release another row's lock.
                        // Requests for different locations may complete in any order.
                        if (isBulk) {
                            isDispatching = false;
                        } else {
                            pendingLocations.delete(requestData.location);
                        }
                        renderCurrentRows();
                    }
                });
            }

            $(document).on('click', '.js-tech-bulk-command', function () {
                if (isDispatching || pendingLocations.size > 0 || !statusesReady || !statusRows.length) {
                    return;
                }
                const $button = $(this);
                const actionLabel = $button.data('label');
                if (!window.confirm(actionLabel + ' for ' + statusRows.length + ' locations, including offline or unknown devices?')) {
                    return;
                }
                sendDeviceAction($button, commandAllUrl, {
                    _token: @json(csrf_token()),
                    command: $button.data('command')
                }, actionLabel, 'Sending command...');
            });

            $(document).on('click', '.js-tech-check-pi', function () {
                const $button = $(this);
                const location = $button.data('location');

                if (isDispatching || pendingLocations.has(location) || !location) {
                    return;
                }

                sendDeviceAction(
                    $button,
                    checkUrl,
                    {
                        _token: @json(csrf_token()),
                        location: location
                    },
                    'PI response check',
                    'Checking...'
                );
            });

            $(document).on('click', '.js-tech-device-command', function () {
                const $button = $(this);
                const location = $button.data('location');
                const command = $button.data('command');
                const commandLabel = {
                    restart_device: 'Restart command',
                    open_door: 'Open door command',
                    close_door: 'Close door command'
                }[command] || 'Internet test command';

                if (isDispatching || pendingLocations.has(location) || !location || !command) {
                    return;
                }

                if (command === 'restart_device' && !window.confirm('Restart the device at ' + location + '?')) {
                    return;
                }

                if (command === 'open_door' && !window.confirm('Open the door at ' + location + '?')) {
                    return;
                }

                // Closing uses the same confirmation and per-location lock as
                // opening; the Pi handler decides how the hardware responds.
                if (command === 'close_door' && !window.confirm('Close the door at ' + location + '?')) {
                    return;
                }

                sendDeviceAction(
                    $button,
                    commandUrl,
                    {
                        _token: @json(csrf_token()),
                        location: location,
                        command: command
                    },
                    commandLabel,
                    'Sending command...'
                );
            });

            window.waitForShopifySessionToken(function () {
                loadStatuses();
                window.setInterval(loadStatuses, pollingIntervalMs);
            });
        });
    </script>
@endsection
