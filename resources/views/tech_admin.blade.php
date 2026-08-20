@extends('shopify-app::layouts.default')

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
</style>
@endsection

@section('content')
{{-- Decide standalone vs embedded as early as possible — BEFORE the App Bridge
     nav/title-bar elements below are parsed — so the hide CSS applies with no
     visible flash. window.self === window.top is only true when the page is NOT
     embedded in the Shopify admin iframe (i.e. opened by direct URL). --}}
<script>
    if (window.self === window.top) {
        document.documentElement.classList.add('tech-admin-standalone');
    }
</script>

@include('partials.app_nav')

<s-page heading="Tech Admin">
    @include('partials.app_page_actions', ['primaryAction' => ['label' => 'Location Order Overview', 'path' => '/orders']])
</s-page>

{{-- Plain heading shown only in standalone mode (see the styles block); embedded
     mode uses the native <s-page heading="Tech Admin"> above. --}}
<h1 class="tech-admin-standalone-heading h4 px-2 pt-2 mb-0">Tech Admin</h1>

<div class="container-fluid p-2">
    <div class="admin-help-row">
        <span class="fw-semibold">Page help</span>
        @include('partials.admin_help_tooltip', ['text' => 'Use this page to monitor the latest Raspberry Pi heartbeat, Ethernet or WiFi connection, CPU and vending-machine temperatures, internet speed, lock and door-sensor state, and recent online state for every active location.'])
    </div>
    <div class="admin-help-row">
        <span class="fw-semibold">Pi status table</span>
        @include('partials.admin_help_tooltip', ['text' => 'Each row combines database location and store mapping with the most recent Pi heartbeat stored in Laravel. The door column prefers lock_status plus door_sensor_status from the Pi payload and falls back to the older legacy door_status when needed.'])
    </div>
    <div class="d-flex justify-content-end mb-2">
        <span class="tech-admin-meta" id="tech-admin-last-updated">Last update: waiting for first refresh</span>
    </div>
    {{-- Toast host for the manual "Check PI Response" result. Toasts are created on
         demand into this fixed top-right container and auto-dismiss. Green = broker
         received + Pi replied, amber = broker received but the Pi stayed silent,
         red = broker rejected the publish. --}}
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
                    <th>Check PI Response</th>
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
    @parent
    @include('partials.app_navigation')

    <script type="text/javascript">
        $(function () {
            const statusesUrl = @json(route('tech_admin.statuses'));
            const checkUrl = @json(route('tech_admin.check_pi'));
            const pollingIntervalMs = 15000;
            let isLoadingStatuses = false;

            function escapeHtml(value) {
                return $('<div>').text(value ?? '').html();
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
                const checkButton = [
                    '<div class="tech-admin-actions">',
                        '<button type="button" class="btn btn-sm btn-outline-primary js-tech-check-pi" data-location="' + escapeHtml(row.location) + '">',
                            'Check PI Response',
                        '</button>',
                    '</div>'
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
                        '<td>' + checkButton + '</td>',
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

            function replaceOrAppendRow(row) {
                if (!row || !row.location_slug) {
                    return false;
                }

                const $tbody = $('#techAdminTable tbody');
                const $existingRow = $tbody.find('tr[data-location-slug="' + row.location_slug + '"]');
                const existingIndexLabel = $existingRow.length
                    ? $existingRow.children().first().text().trim()
                    : (($tbody.children('tr').length + 1) + '.');
                const rowHtml = renderRow(row, existingIndexLabel || '-');

                if ($existingRow.length) {
                    $existingRow.replaceWith(rowHtml);
                    return true;
                }

                const hasPlaceholderRow = $tbody.find('tr td[colspan="14"]').length > 0;

                if (hasPlaceholderRow) {
                    $tbody.html(rowHtml);
                    return true;
                }

                $tbody.append(rowHtml);

                return true;
            }

            function updateLastUpdatedLabel(isoValue) {
                if (!isoValue) {
                    return;
                }

                $('#tech-admin-last-updated').text('Last update: ' + isoValue);
            }

            // Show a Bootstrap toast for the manual Pi-check result. "type" is a
            // Bootstrap contextual suffix: success (green), warning (amber) or
            // danger (red). Each call builds its own toast, so repeated checks stack
            // and then auto-dismiss; the element is removed from the DOM once hidden.
            function showToast(message, type) {
                const container = document.getElementById('tech-admin-toast-container');

                if (!container || !window.bootstrap || !window.bootstrap.Toast) {
                    return;
                }

                const toastEl = document.createElement('div');
                toastEl.className = 'toast align-items-center text-bg-' + type + ' border-0';
                toastEl.setAttribute('role', 'alert');
                toastEl.setAttribute('aria-live', 'assertive');
                toastEl.setAttribute('aria-atomic', 'true');

                // The amber (warning) toast has a light background, so its close
                // button must stay dark; the green/red toasts use the white variant.
                const closeButtonClass = type === 'warning' ? 'btn-close' : 'btn-close btn-close-white';

                toastEl.innerHTML =
                    '<div class="d-flex">' +
                        '<div class="toast-body"></div>' +
                        '<button type="button" class="' + closeButtonClass + ' me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
                    '</div>';

                // Use textContent so the location name can never inject markup.
                toastEl.querySelector('.toast-body').textContent = message;
                container.appendChild(toastEl);

                const toast = new window.bootstrap.Toast(toastEl, { autohide: true, delay: 6000 });
                toastEl.addEventListener('hidden.bs.toast', function () {
                    toastEl.remove();
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
                        const rows = Array.isArray(response.data) ? response.data : [];
                        $('#techAdminTable tbody').html(renderRows(rows));
                        updateLastUpdatedLabel(response.meta ? response.meta.generated_at : null);
                        initializeAdminHelpTooltips();
                    },
                    error: function (xhr) {
                        const errorMessage = window.getAjaxErrorMessage(xhr, 'Unable to load Pi status rows.');
                        $('#techAdminTable tbody').html(
                            '<tr><td colspan="14" class="text-center text-danger">' + escapeHtml(errorMessage) + '</td></tr>'
                        );
                    },
                    complete: function () {
                        isLoadingStatuses = false;
                    }
                });
            }

            $(document).on('click', '.js-tech-check-pi', function () {
                const $button = $(this);
                const location = $button.data('location');

                if (!location) {
                    return;
                }

                $button.prop('disabled', true).text('Checking...');

                $.ajax({
                    url: checkUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        _token: @json(csrf_token()),
                        location: location
                    },
                    success: function (response) {
                        const data = response && response.data ? response.data : {};
                        const latestRow = data.latest_row || null;
                        const rowWasUpdated = replaceOrAppendRow(latestRow);

                        updateLastUpdatedLabel(response && response.meta ? response.meta.generated_at : null);

                        if (!rowWasUpdated) {
                            loadStatuses();
                        }

                        // Report the outcome to the user. "published" = the MQTT broker
                        // accepted our check command; "pi_replied" = a fresh heartbeat
                        // came back from the device within the ~12s wait window. A publish
                        // failure returns HTTP 500 and is handled by the error branch below.
                        if (data.published && data.pi_replied) {
                            showToast('MQTT broker received the check and ' + location + ' replied — the Pi is online.', 'success');
                        } else if (data.published) {
                            showToast('MQTT broker received the check, but ' + location + ' did not reply within ~12s — the Pi may be offline.', 'warning');
                        }
                    },
                    error: function (xhr) {
                        showToast(window.getAjaxErrorMessage(xhr, 'MQTT broker did not accept the check (publish failed).'), 'danger');
                    },
                    complete: function () {
                        $button.prop('disabled', false).text('Check PI Response');
                    }
                });
            });

            window.waitForShopifySessionToken(function () {
                loadStatuses();
                window.setInterval(loadStatuses, pollingIntervalMs);
            });
        });
    </script>
@endsection
