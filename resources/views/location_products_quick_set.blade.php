@extends('shopify-app::layouts.default')

@section('styles')
<style>
    .quick-set-summary {
        border: 1px solid #dee2e6;
        border-radius: 0.75rem;
        background: #fff;
    }

    .quick-set-total {
        min-width: 56px;
        display: inline-block;
    }

    .quick-set-total-btn {
        cursor: pointer;
    }

    .quick-set-total-btn:disabled {
        cursor: not-allowed;
        opacity: 0.65;
    }

    .quick-set-product-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
    }

    .quick-set-loading {
        display: none;
    }

    .quick-set-loading.show {
        display: inline-block;
    }
</style>
@endsection

@section('content')
@include('partials.app_nav')

<s-page heading="Location Products Quick Set">
    <s-button slot="primary-action" onclick="navigateToPage('/location_products')">Location Products</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/orders')">Location Order Overview</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/stores')">Stores</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/locations_revenue')">Locations Revenue</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/locations_text')">Location Settings</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/kitchen/ADMIN?menu=1')">Kitchen</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/drivers/ADMIN?menu=1')">Drivers</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/order_details_for_rpi/ADMIN?menu=1')">RPI Order Details</s-button>
</s-page>

<div class="container-fluid p-2">
    <div class="quick-set-summary p-3 p-md-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div>
                <h5 class="mb-1">Today Immediate Inventory</h5>
                <p class="text-muted mb-0">
                    Day: <strong id="quick_set_day_label">{{ $todayDay }}</strong>,
                    date: <strong id="quick_set_date_label">{{ $todayDate }}</strong>
                </p>
            </div>

            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-danger" id="quick_set_delete_all">
                    Delete All Today
                </button>
                <div class="spinner-border spinner-border-sm text-danger quick-set-loading" id="quick_set_loading" role="status"></div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Location</th>
                        <th>{{ $todayDate }} Immediate Inventory</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody id="quick_set_rows">
                    @forelse($rows as $row)
                        <tr>
                            <td>{{ $row['location'] }}</td>
                            <td>
                                <button
                                    type="button"
                                    class="border-0 bg-transparent p-0 quick-set-total-btn"
                                    data-location="{{ $row['location'] }}"
                                    aria-label="View today immediate inventory products for {{ $row['location'] }}"
                                >
                                    <span class="badge text-bg-primary quick-set-total">{{ $row['total_quantity'] }}</span>
                                </button>
                            </td>
                            <td class="text-end">
                                <button
                                    type="button"
                                    class="btn btn-danger btn-sm quick-set-delete-btn"
                                    data-location="{{ $row['location'] }}"
                                >
                                    Delete Today
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted py-4">No quick-set locations are available.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal" tabindex="-1" id="quick_set_products_modal">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="quick_set_products_modal_label">Today Immediate Inventory Products</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="quick_set_products_modal_body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
    @parent
    @include('partials.app_navigation')
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script type="text/javascript">
        $(function () {
            const resetUrl = "{{ route('location_products.quick_set.reset_today') }}";
            const shopDomain = @json($shopDomain ?? '');
            const shopifyAdminProductBaseUrl = @json(($shopDomain ?? '') !== '' ? "https://{$shopDomain}/admin/products/" : '');
            let currentRows = @json($rows);

            if (!Array.isArray(currentRows)) {
                currentRows = [];
            }

            function resolveAjaxErrorMessage(xhr, fallbackMessage) {
                const response = xhr && xhr.responseJSON ? xhr.responseJSON : null;

                if (response && response.message) {
                    return response.message;
                }

                if (xhr && xhr.statusText) {
                    return xhr.statusText;
                }

                return fallbackMessage;
            }

            function escapeHtml(value) {
                return $('<div>').text(value ?? '').html();
            }

            function getRowByLocation(location) {
                return currentRows.find(function (row) {
                    return row.location === location;
                }) || null;
            }

            function buildProductUrl(productId) {
                if (!shopifyAdminProductBaseUrl || !productId) {
                    return '';
                }

                return `${shopifyAdminProductBaseUrl}${productId}`;
            }

            // The modal reads from the latest response payload so the drilldown
            // always matches the badge count shown in the table.
            function openProductsModal(location) {
                const row = getRowByLocation(location);
                const products = row && Array.isArray(row.products) ? row.products : [];

                $('#quick_set_products_modal_label').text(`${location} Products`);

                if (products.length === 0) {
                    $('#quick_set_products_modal_body').html(
                        '<p class="text-muted mb-0">No immediate inventory products found for this location today.</p>'
                    );
                    $('#quick_set_products_modal').modal('show');
                    return;
                }

                const html = products.map(function (product) {
                    const productId = Number(product.product_id || 0);
                    const productTitle = escapeHtml(product.title || `Product #${productId}`);
                    const productQuantity = Number(product.quantity || 0);
                    const productUrl = buildProductUrl(productId);
                    const productLabel = productUrl
                        ? `<a href="${escapeHtml(productUrl)}" target="_blank" rel="noopener noreferrer">${productTitle}</a>`
                        : `<span>${productTitle}</span>`;

                    return `
                        <div class="list-group-item">
                            <div class="quick-set-product-row">
                                ${productLabel}
                                <span class="badge text-bg-primary">${productQuantity}</span>
                            </div>
                        </div>
                    `;
                }).join('');

                $('#quick_set_products_modal_body').html(`<div class="list-group list-group-flush">${html}</div>`);
                $('#quick_set_products_modal').modal('show');
            }

            // Rebuild the table after every delete so the UI always reflects the
            // server's current totals and stays safe for repeated top-to-bottom use.
            function renderRows(rows) {
                const $tbody = $('#quick_set_rows');
                currentRows = Array.isArray(rows) ? rows : [];

                if (currentRows.length === 0) {
                    $tbody.html('<tr><td colspan="3" class="text-center text-muted py-4">No quick-set locations are available.</td></tr>');
                    return;
                }

                const html = currentRows.map(function (row) {
                    const safeLocation = escapeHtml(row.location);
                    const totalQuantity = Number(row.total_quantity || 0);

                    return `
                        <tr>
                            <td>${safeLocation}</td>
                            <td>
                                <button
                                    type="button"
                                    class="border-0 bg-transparent p-0 quick-set-total-btn"
                                    data-location="${safeLocation}"
                                    aria-label="View today immediate inventory products for ${safeLocation}"
                                >
                                    <span class="badge text-bg-primary quick-set-total">${totalQuantity}</span>
                                </button>
                            </td>
                            <td class="text-end">
                                <button
                                    type="button"
                                    class="btn btn-danger btn-sm quick-set-delete-btn"
                                    data-location="${safeLocation}"
                                >
                                    Delete Today
                                </button>
                            </td>
                        </tr>
                    `;
                }).join('');

                $tbody.html(html);
            }

            function setBusyState(isBusy) {
                $('#quick_set_loading').toggleClass('show', isBusy);
                $('#quick_set_delete_all').prop('disabled', isBusy);
                $('.quick-set-delete-btn').prop('disabled', isBusy);
                $('.quick-set-total-btn').prop('disabled', isBusy);
            }

            function submitResetInventory(location) {
                setBusyState(true);

                $.ajax({
                    url: resetUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        _token: '{{ csrf_token() }}',
                        location: location || ''
                    },
                    success: function (response) {
                        if (response.day) {
                            $('#quick_set_day_label').text(response.day);
                        }

                        if (response.date) {
                            $('#quick_set_date_label').text(response.date);
                        }

                        renderRows(response.rows || []);
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted',
                            text: response.message || 'Today immediate inventory was deleted successfully.',
                            confirmButtonText: 'Close'
                        });
                    },
                    error: function (xhr) {
                        const errorMessage = resolveAjaxErrorMessage(
                            xhr,
                            'Unable to delete today immediate inventory.'
                        );

                        Swal.fire({
                            icon: 'error',
                            title: 'Unable to delete',
                            text: errorMessage,
                            confirmButtonText: 'Close'
                        });
                    },
                    complete: function () {
                        setBusyState(false);
                    }
                });
            }

            function resetInventory(location) {
                const confirmText = location
                    ? `Delete today's immediate inventory for ${location}?`
                    : "Delete today's immediate inventory for all listed locations?";

                Swal.fire({
                    icon: 'warning',
                    title: 'Delete today inventory',
                    text: confirmText,
                    showCancelButton: true,
                    confirmButtonText: 'Delete',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#dc3545',
                    reverseButtons: true
                }).then(function (result) {
                    if (result.isConfirmed) {
                        submitResetInventory(location);
                    }
                });
            }

            $(document).on('click', '.quick-set-total-btn', function () {
                openProductsModal($(this).data('location'));
            });

            $(document).on('click', '.quick-set-delete-btn', function () {
                resetInventory($(this).data('location'));
            });

            $(document).on('click', '#quick_set_delete_all', function () {
                resetInventory(null);
            });
        });
    </script>
@endsection
