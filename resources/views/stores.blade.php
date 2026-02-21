@extends('shopify-app::layouts.default')

@section('styles')
@livewireStyles()
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/fontawesome.min.css" integrity="sha512-v8QQ0YQ3H4K6Ic3PJkym91KoeNT5S3PnDKvqnwqFD1oiqIl653crGZplPdU5KKtHjO0QKcQ2aUlQZYjHczkmGw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/solid.min.css" integrity="sha512-DzC7h7+bDlpXPDQsX/0fShhf1dLxXlHuhPBkBo/5wJWRoTU6YL7moeiNoej6q3wh5ti78C57Tu1JwTNlcgHSjg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<style>
    .edit_button, .delete_button { margin-right: 5px; }
</style>
@endsection

@section('content')
{{-- AppBridge v4 App Nav - Global Navigation Menu --}}
@include('partials.app_nav')

{{-- AppBridge v4 Title Bar using s-page web component --}}
<s-page heading="Stores">
    {{-- Primary action button - navigates to Location Order Overview --}}
    <s-button slot="primary-action" onclick="navigateToPage('/orders')">Location Order Overview</s-button>

    {{-- Secondary action buttons for navigation --}}
    <s-button slot="secondary-actions" onclick="navigateToPage('/location_products')">Location Products</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/locations_revenue')">Locations Revenue</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/kitchen/ADMIN?menu=1')">Kitchen</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/drivers/ADMIN?menu=1')">Drivers</s-button>
    {{-- Temporarily hidden --}}
    {{-- <s-button slot="secondary-actions" onclick="navigateToPage('/home_delivery')">Home Delivery Overview</s-button> --}}
    <s-button slot="secondary-actions" onclick="navigateToPage('/locations_text')">Location Settings</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/order_details_for_rpi/ADMIN?menu=1')">RPI Order Details</s-button>
</s-page>

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
                    <th>UUID</th>
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
    @include('partials.app_navigation')

    @livewireScripts()

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

            // Handle share link button clicks (delegated) to copy URL
            $(document).on('click', '.share-link-btn', async function() {
                var $btn = $(this);
                var href = $btn.data('href');
                if (!href) {
                    // Fallback: try to find sibling anchor
                    href = $btn.siblings('a').attr('href') || '';
                }
                if (!href) return;

                const icon = $btn.find('i');
                const originalClass = icon.attr('class');

                // Try modern clipboard API first
                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(href);
                    } else {
                        throw new Error('Insecure context or no clipboard API');
                    }
                } catch (e) {
                    // Fallback to execCommand copy
                    var $temp = $('<input>');
                    $('body').append($temp);
                    $temp.val(href).select();
                    document.execCommand('copy');
                    $temp.remove();
                }

                // Quick visual feedback: swap icon to check briefly
                icon.removeClass().addClass('fa fa-check text-success');
                setTimeout(function(){
                    icon.removeClass().addClass(originalClass);
                }, 1200);
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
