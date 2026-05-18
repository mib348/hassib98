@extends('shopify-app::layouts.default')

@section('styles')
<style>
    .order_counter, .items_counter{cursor: pointer;}
</style>
@endsection

@section('content')
{{-- AppBridge v4 App Nav - Global Navigation Menu --}}
@include('partials.app_nav')

{{-- AppBridge v4 Title Bar using s-page web component --}}
<s-page heading="Home Delivery Overview">
    @include('partials.app_page_actions', ['primaryAction' => ['label' => 'Location Products', 'path' => '/location_products']])
</s-page>

<div class="container-fluid p-2">
    <div class="row">
        <div class="col-md-12">
            @if((int) request('menu') === 1)
            <div class="admin-help-row">
                <span class="fw-semibold">Page help</span>
                @include('partials.admin_help_tooltip', ['text' => 'Use this overview to compare home delivery demand across each configured timezone slot and to inspect the orders counted in each bucket.'])
            </div>
            <div class="admin-help-row">
                <span class="fw-semibold">Delivery table</span>
                @include('partials.admin_help_tooltip', ['text' => 'Each column represents one configured delivery timezone. Click the order counters to open the matching orders for that slot.'])
            </div>
            @endif
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Timezone 1<br>Order limit: {{ $arrLocation->time_order_limit }}</th>
                            <th>Timezone 2<br>Order limit: {{ $arrLocation->time2_order_limit }}</th>
                            <th>Timezone 3<br>Order limit: {{ $arrLocation->time3_order_limit }}</th>
                            <th>Timezone 4<br>Order limit: {{ $arrLocation->time4_order_limit }}</th>
                            <th>Timezone 5<br>Order limit: {{ $arrLocation->time5_order_limit }}</th>
                            <th>Total Orders</th>
                        </tr>
                    </thead>
                    <tbody>
                        {!! $html !!}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal" tabindex="-1" id="orders_list_modal">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><span id="order_type"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="orders_list">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          {{-- <button type="button" class="btn btn-primary">Save changes</button> --}}
        </div>
      </div>
    </div>
  </div>

@endsection

@section('scripts')
    @parent
    @include('partials.app_navigation')

    <script type="text/javascript">
        $(document).on('click', '.order_counter', function(e){
            const arrayOrders = JSON.parse($(this).attr('data-orders'));
            console.log(arrayOrders);

            var html = '<ul>';

            // Loop through each object in the array
            $.each(arrayOrders, function(index, orderObj){
                // Get the first (and only) key from the object
                const orderId = Object.keys(orderObj)[0];
                // Get the value associated with that key
                const orderValue = orderObj[orderId];

                html += '<li><a href="https://admin.shopify.com/store/sushi2024/orders/' + orderId + '" target="_blank">' + orderValue + '</a></li>';
            });

            html += '</ul>';
            $("#orders_list").html(html);
            $("#orders_list_modal").modal('show');
        });
    </script>
@endsection
