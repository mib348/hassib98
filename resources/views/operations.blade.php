@extends('shopify-app::layouts.default')

@section('styles')
<style>
</style>
@endsection

@section('content')
{{-- AppBridge v4 App Nav - Global Navigation Menu --}}
@include('partials.app_nav')

{{-- AppBridge v4 Title Bar using s-page web component --}}
<s-page heading="Operations">
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
    {{-- <h5>Orders <div class="loader spinning_status"></div></h5> --}}
    {{-- <div class="row">
        <div class="col-6">
            <h5>Orders</h5>
        </div>
        <div class="col-6 d-flex flex-row flex-wrap align-items-center justify-content-end mb-3">
            <div class="d-grid gap-2 d-md-block">
                <a href="https://admin.shopify.com/store/dc9ef9/apps/sushi-catering-1/products" class="btn btn-primary">Products</a>
              </div>
        </div>
    </div> --}}
    <div class="row">
        <div class="col-md-12">
            <form id="operation_days_form">
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full">
                    <thead>
                        <tr>
                            <th>

                            </th>
                            <th>Monday</th>
                            <th>Tuesday</th>
                            <th>Wednesday</th>
                            <th>Thursday</th>
                            <th>Friday</th>
                            <th>Saturday</th>
                            <th>Sunday</th>
                        </tr>
                    </thead>
                    <tbody>
                        {!! $html !!}
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-primary" id="save_btn">Save</button>
            </form>
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

              $(document).on('click', '#save_btn', function(e){
                $.ajax({
                    url:"{{ route('operationdays.store') }}",
                    type:"POST",
                    data: {
                        "_token": "{{ csrf_token() }}",
                        "strFilterLocation": $("#strFilterLocation").val(),
                        "operation_days_form": $("#operation_days_form").serialize()
                    },
                    cache:false,
                    dataType:"json",
                    success:function(data){
                        alert('Operation Days Saved Successfully');
                    },
                    error: function (request, status, error) {
                        alert('error saving operation days');
                    }
                });
              });

    		//LoadList();
        });

        function LoadList(){
        	$.ajax({
            	url:"{{ route('getOperationDaysList') }}",
            	type:"GET",
            	data: {
                    "_token": "{{ csrf_token() }}",
                    "strFilterLocation": $("#strFilterLocation").val()
            	},
            	cache:false,
            	dataType:"html",
            	success:function(data){
            		table.clear();
            		table.rows.add($(data)).draw(true);
//                 	$(".table tbody").html(data);
                },
                error: function (request, status, error) {
                    console.log('orders fetching error');
                }
            });
        }
    </script>
@endsection
