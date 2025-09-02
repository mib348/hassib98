@extends('shopify-app::layouts.default')

@section('styles')
@livewireStyles()
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/fontawesome.min.css" integrity="sha512-v8QQ0YQ3H4K6Ic3PJkym91KoeNT5S3PnDKvqnwqFD1oiqIl653crGZplPdU5KKtHjO0QKcQ2aUlQZYjHczkmGw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/solid.min.css" integrity="sha512-DzC7h7+bDlpXPDQsX/0fShhf1dLxXlHuhPBkBo/5wJWRoTU6YL7moeiNoej6q3wh5ti78C57Tu1JwTNlcgHSjg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<style>
    .edit_button, .delete_button, .kitchen_btn{margin-right: 5px;}
</style>
@endsection

@section('content')
<div class="container-fluid p-2">
    <div class="row">
        <div class="col-6">
            <button type="button" class="btn btn-success" onclick="Livewire.dispatch('resetAndOpenModal')">
                <i class="fas fa-plus"></i> Add Store
            </button>
        </div>
        <div class="col-6"></div>
    </div>
    <br>
    <div class="table-responsive" wire:ignore>
        <table id="storesTable" class="table table-bordered table-striped table-hover js-dataTable-full">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Locations</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            </tbody>
        </table>
    </div>
</div>

<livewire:stores.stores-list />

@endsection

@section('scripts')
    @parent
    @livewireScripts()
    <script>
        // Shopify App Bridge configuration for header buttons
        var actions = window['app-bridge'].actions;
        var Button = actions.Button;
        var TitleBar = actions.TitleBar;
        var Redirect = actions.Redirect;

        // Create header buttons for navigation
        var ordersButton = Button.create(app, { label: 'Location Order Overview' });
        ordersButton.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/orders');
        });

        var location_products = Button.create(app, { label: 'Location Products' });
        location_products.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/location_products');
        });

        var locations_revenue = Button.create(app, { label: 'Locations Revenue' });
        locations_revenue.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/locations_revenue');
        });

        var kitchen = Button.create(app, { label: 'Kitchen' });
        kitchen.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/kitchen/ADMIN');
        });

        var homedeliveryButton = Button.create(app, { label: 'Home Delivery Overview' });
        homedeliveryButton.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/home_delivery');
        });

        // Create a button for 'Locations Text'
        var locations_text = Button.create(app, { label: 'Location Settings' });
        locations_text.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/locations_text');
        });

        // Update the title bar with buttons
        var titleBar = TitleBar.create(app, {
            title: 'Stores',
            buttons: {
                primary: ordersButton,
                secondary: [location_products, locations_revenue, kitchen, homedeliveryButton, locations_text]
            },
        });
    </script>

    <script type="text/javascript">
        document.addEventListener('reload-list', function() {
            LoadList();
        });

        $(function(){
            window.table = jQuery('.js-dataTable-full').DataTable({
                pageLength: 10,
                lengthMenu: [[5, 10, 20], [5, 10, 20]],
                order:[[0, 'desc']],
                autoWidth: true
            });

            LoadList();

            // Handle edit button clicks using event delegation
            $(document).on('click', '.edit_button', function() {
                var storeId = $(this).closest('tr').data('id');
                // Call Livewire edit method
                Livewire.dispatch('editStore', { storeId: storeId });
            });
            
            // Listen for Livewire event to open modal after data is loaded
            Livewire.on('open-modal', () => {
                $('#addStoreModal').modal('show');
            });

            // Handle delete button clicks using event delegation
            $(document).on('click', '.delete_button', function() {
                var storeId = $(this).closest('tr').data('id');
                // Confirm deletion before proceeding
                if (confirm('Are you sure?')) {
                    // Call Livewire delete method
                    Livewire.dispatch('deleteStore', { storeId: storeId });
                }
            });
        });

        function LoadList(){
        	$.ajax({
            	url:"{{ route('getStoresList') }}",
            	type:"GET",
            	data: {
                    "_token": "{{ csrf_token() }}"
            	},
            	cache:false,
            	dataType:"html",
            	success:function(data){
            		table.clear();
            		table.rows.add($(data)).draw(true);
//                 	$(".table tbody").html(data);
                },
                error: function(request, status, error) {
                    // var errorMessage = "Error: " + request.status + " " + request.statusText;
                    alert(request.responseText);
                    console.log('stores fetching error');
                }
            });
        }
    </script>
@endsection
