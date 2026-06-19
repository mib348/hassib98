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

    .tech-admin-meta {
        color: #6c757d;
        font-size: 0.9rem;
    }

    .tech-admin-actions {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
</style>
@endsection

@section('content')
@include('partials.app_nav')

<s-page heading="Tech Admin">
    @include('partials.app_page_actions', ['primaryAction' => ['label' => 'Location Order Overview', 'path' => '/orders']])
</s-page>

<div class="container-fluid p-2">
    <div class="admin-help-row">
        <span class="fw-semibold">Page help</span>
        @include('partials.admin_help_tooltip', ['text' => 'Use this page to monitor the latest Raspberry Pi heartbeat, WiFi signal, door status, and recent online state for every active location.'])
    </div>
    <div class="admin-help-row">
        <span class="fw-semibold">Pi status table</span>
        @include('partials.admin_help_tooltip', ['text' => 'Each row combines database location and store mapping with the most recent Pi heartbeat stored in Laravel. The Check PI Response button sends a manual MQTT health request and the table refreshes automatically.'])
    </div>
    <div class="d-flex justify-content-end mb-2">
        <span class="tech-admin-meta" id="tech-admin-last-updated">Last update: waiting for first refresh</span>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered table-striped table-hover table-vcenter" id="techAdminTable">
            <thead>
                <tr>
                    <th>Location</th>
                    <th>Store</th>
                    <th>Client ID</th>
                    <th>PI Status</th>
                    <th>App Status</th>
                    <th>WiFi Status</th>
                    <th>Door Status</th>
                    <th>Online Since</th>
                    <th>Check PI Response</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="9" class="text-center text-muted">Loading Pi status rows...</td>
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

            function renderRow(row) {
                const checkButton = [
                    '<div class="tech-admin-actions">',
                        '<button type="button" class="btn btn-sm btn-outline-primary js-tech-check-pi" data-location="' + escapeHtml(row.location) + '">',
                            'Check PI Response',
                        '</button>',
                    '</div>'
                ].join('');

                return [
                    '<tr>',
                        '<td>' + escapeHtml(row.location || '-') + '</td>',
                        '<td>' + escapeHtml(row.store || '-') + '</td>',
                        '<td>' + escapeHtml(row.client_id || '-') + '</td>',
                        '<td>' + renderStatusBadge(row.pi_status) + '</td>',
                        '<td>' + renderStatusBadge(row.app_status) + '</td>',
                        '<td>' + escapeHtml(row.wifi_status || '-') + '</td>',
                        '<td>' + escapeHtml(row.door_status || '-') + '</td>',
                        '<td>' + escapeHtml(row.online_since || '-') + '</td>',
                        '<td>' + checkButton + '</td>',
                    '</tr>'
                ].join('');
            }

            function renderRows(rows) {
                if (!rows.length) {
                    return '<tr><td colspan="9" class="text-center text-muted">No active locations found.</td></tr>';
                }

                return rows.map(renderRow).join('');
            }

            function updateLastUpdatedLabel(isoValue) {
                if (!isoValue) {
                    return;
                }

                $('#tech-admin-last-updated').text('Last update: ' + isoValue);
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
                            '<tr><td colspan="9" class="text-center text-danger">' + escapeHtml(errorMessage) + '</td></tr>'
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
                    success: function () {
                        loadStatuses();
                    },
                    error: function (xhr) {
                        alert(window.getAjaxErrorMessage(xhr, 'Unable to request PI check.'));
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
