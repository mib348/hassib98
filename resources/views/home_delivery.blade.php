@extends('shopify-app::layouts.default')

@section('styles')
<style>
    .order_counter, .items_counter{cursor: pointer;}
</style>
@endsection

@section('content')
<div class="container-fluid p-2">
    <div class="row">
        <div class="col-md-12">
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


    <script>
        // Assuming 'app' is already initialized and available
        // var actions = window['app-bridge'].actions;
        var Button = actions.Button;
        var TitleBar = actions.TitleBar;
        var Redirect = actions.Redirect; // Ensure Redirect is defined

        // // Create a button for 'Products'
        // var productsButton = Button.create(app, { label: 'Products' });
        // productsButton.subscribe(Button.Action.CLICK, function() {
        //     var redirect = Redirect.create(app);
        //     redirect.dispatch(Redirect.Action.APP, '/products');
        //     // Add your logic for when the 'Products' button is clicked
        // });

        // // Create a button for 'Operation'
        // var operationdays = Button.create(app, { label: 'Operation Days' });
        // operationdays.subscribe(Button.Action.CLICK, function() {
        //     var redirect = Redirect.create(app);
        //     redirect.dispatch(Redirect.Action.APP, '/operationdays');
        //     // Add your logic for when the 'Operation' button is clicked
        // });

        // Create a button for 'Location Products'
        var location_products = Button.create(app, { label: 'Location Products' });
        location_products.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/location_products');
            // Add your logic for when the 'Operation' button is clicked
        });


        // Create a button for 'Locations Revenue'
        var locations_revenue = Button.create(app, { label: 'Locations Revenue' });
        locations_revenue.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/locations_revenue');
            // Add your logic for when the 'Locations Revenue' button is clicked
        });

        // Create a button for 'Locations Text'
        var locations_text = Button.create(app, { label: 'Location Settings' });
        locations_text.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/locations_text');
            // Add your logic for when the 'Locations Revenue' button is clicked
        });

        // Create a button for 'Kitchen'
        var kitchen = Button.create(app, { label: 'Kitchen' });
        kitchen.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/kitchen');
            // Add your logic for when the 'Locations Revenue' button is clicked
        });

        // Create a button for 'Orders'
        var ordersButton = Button.create(app, { label: 'Location Order Overview' });
        ordersButton.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/orders');
            // Add your logic for when the 'Orders' button is clicked
        });

        // Create a button for 'Store'
        var stores = Button.create(app, { label: 'Stores' });
        stores.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/stores');
            // Add your logic for when the 'Locations Revenue' button is clicked
        });


        // Update the title bar with the new buttons
        var titleBar = TitleBar.create(app, {
            title: 'Home Delivery Overview',
            buttons: {
                primary: location_products,
                secondary: [stores, locations_revenue, locations_text, kitchen, ordersButton]
            },
        });
    </script>

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
