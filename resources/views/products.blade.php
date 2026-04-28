@extends('shopify-app::layouts.default')

@section('styles')
@endsection

@section('content')
{{-- AppBridge v4 App Nav - Global Navigation Menu --}}
@include('partials.app_nav')

{{-- AppBridge v4 Title Bar using s-page web component --}}
<s-page heading="Products">
    {{-- Primary action button - navigates to Location Order Overview --}}
    <s-button slot="primary-action" onclick="navigateToPage('/orders')">Location Order Overview</s-button>

    {{-- Secondary action buttons for navigation --}}
    <s-button slot="secondary-actions" onclick="navigateToPage('/stores')">Stores</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/location_products')">Location Products</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/locations_revenue')">Locations Revenue</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/locations_text')">Location Settings</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/kitchen/ADMIN?menu=1')">Kitchen</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/drivers/ADMIN?menu=1')">Drivers</s-button>
    {{-- Temporarily hidden --}}
    {{-- <s-button slot="secondary-actions" onclick="navigateToPage('/home_delivery')">Home Delivery Overview</s-button> --}}
    <s-button slot="secondary-actions" onclick="navigateToPage('/order_details_for_rpi/ADMIN?menu=1')">RPI Order Details</s-button>
</s-page>

<div class="container-fluid p-2">
    <div class="admin-help-row">
        <span class="fw-semibold">Page help</span>
        @include('partials.admin_help_tooltip', ['text' => 'This page lists products with their scheduled quantities and configured days so admins can inspect what inventory logic is attached to each product.'])
    </div>
    {{-- <h5>Products <div class="loader spinning_status"></div></h5> --}}
    {{-- <div class="row">
        <div class="col-6">
            <h5>Products</h5>
        </div>
        <div class="col-6 d-flex flex-row flex-wrap align-items-center justify-content-end mb-3">
            <div class="d-grid gap-2 d-md-block">
                <a href="https://admin.shopify.com/store/dc9ef9/apps/sushi-catering-1/orders" class="btn btn-primary">Orders</a>
              </div>
        </div>
    </div> --}}
    <div class="row">
        <div class="col-md-12">
            {{-- <select id="strFilterLocation" name="strFilterLocation" class="form-select">
                <option value="" selected>--- Select Location ---</option>
                @foreach($locations as $location)
                <option value="{{ $location }}">{{ $location }}</option>
                @endforeach
            </select> --}}
            <div class="admin-help-row">
                <span class="fw-semibold">Products table</span>
                @include('partials.admin_help_tooltip', ['text' => 'Review each product id, display title, stored date-and-quantity values, active weekday settings, and any row actions exposed in the final column.'])
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full">
                    <thead>
                        <tr>
                            <th>Id</th>
                            <th>Product</th>
                            <th class="text-center">Date - Qty</th>
                            <th class="text-center">Days</th>
                            <th class="text-center">Action</th>
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
@endsection

@section('scripts')
    @parent
    @include('partials.app_navigation')

    <script type="text/javascript">
    	$(function(){
    	      window.table = jQuery('.js-dataTable-full').DataTable({
    	          pageLength: 10,
    	          lengthMenu: [[5, 10, 20], [5, 10, 20]],
    	          order:[[0, 'desc']],
    	          autoWidth: false
    	      });

              $(document).on('change', '#strFilterLocation', function(e){
                LoadList();
              });

    		//LoadList();
        });

        function LoadList(){
        	$.ajax({
            	url:"{{ route('getProductsList') }}",
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
                error: function (request, status, error) {
                    console.log('products error');
                }
            });
        }
    </script>
@endsection
