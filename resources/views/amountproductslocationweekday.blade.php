@extends('shopify-app::layouts.default')

@section('styles')
<style>
</style>
@endsection

@section('content')
{{-- AppBridge v4 App Nav - Global Navigation Menu --}}
@include('partials.app_nav')

{{-- AppBridge v4 Title Bar using s-page web component --}}
<s-page heading="Amount Products Location Weekday">
    @include('partials.app_page_actions', ['primaryAction' => ['label' => 'Location Order Overview', 'path' => '/orders']])
</s-page>

<div class="container-fluid p-2">
    <div class="admin-help-row">
        <span class="fw-semibold">Page help</span>
        @include('partials.admin_help_tooltip', ['text' => 'Adjust product quantities for one location and weekday combination. This screen is useful when admins need a focused edit instead of the full inventory grid.'])
    </div>
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
            <form id="amount_products_location_weekdays_form">
            <div class="admin-help-row">
                <span class="fw-semibold">Focused quantity table</span>
                @include('partials.admin_help_tooltip', ['text' => 'Choose a location and day first, then edit the generated product quantities for that single combination before saving.'])
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full">
                    <thead>
                        <tr>
                            <th colspan="2">
                                <select id="strFilterLocation" name="strFilterLocation" class="form-select">
                                    <option value="" selected>--- Select Location ---</option>
                                    @foreach($locations as $location)
                                    <option value="{{ $location }}">{{ $location }}</option>
                                    @endforeach
                                </select>
                            </th>
                            <th colspan="2">
                                <select id="strFilterDay" name="strFilterDay" class="form-select">
                                    <option value="" selected>--- Select Day ---</option>
                                    @foreach($arrDays as $day)
                                    <option value="{{ $day }}">{{ $day }}</option>
                                    @endforeach
                                </select>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-primary" id="save_btn">Save</button>
            @include('partials.admin_help_tooltip', ['text' => 'Save the currently loaded quantities for the selected location and weekday.'])
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
                  columnDefs: [{ orderable: false, targets: 0 }, { orderable: false, targets: 1 }],
    	          autoWidth: false
    	      });

              $(document).on('change', '#strFilterLocation, #strFilterDay', function(e){
                LoadList();
              });

              $(document).on('click', '#save_btn', function(e){
                $.ajax({
                    url:"{{ route('amountproductslocationweekday.store') }}",
                    type:"POST",
                    // data: {
                    //     "_token": "{{ csrf_token() }}",
                    //     "strFilterLocation": $("#strFilterLocation").val(),
                    //     "strFilterDay": $("#strFilterDay").val(),
                    //     "amount_products_location_weekdays_form": $("#amount_products_location_weekdays_form").serialize()
                    // },
                    data: "_token={{ csrf_token() }}&"+$("#amount_products_location_weekdays_form").serialize(),
                    cache:false,
                    dataType:"json",
                    success:function(data){
                        alert('Amount Products Location Weekday Data Saved Successfully');
                    },
                    error: function (request, status, error) {
                        alert('error saving Amount Products Location Weekday Data');
                    }
                });
              });

    		//LoadList();
        });

        function LoadList(){
        	$.ajax({
            	url:"{{ route('getAmountProductsLocationWeekdayList') }}",
            	type:"GET",
            	data: {
                    "_token": "{{ csrf_token() }}",
                    "strFilterLocation": $("#strFilterLocation").val(),
                    "strFilterDay": $("#strFilterDay").val()
            	},
            	cache:false,
            	dataType:"html",
            	success:function(data){
            		table.clear();
            		table.rows.add($(data)).draw(true);
//                 	$(".table tbody").html(data);
                },
                error: function (request, status, error) {
                    console.log('error fetching Amount Products Location Weekday Data');
                }
            });
        }
    </script>
@endsection
