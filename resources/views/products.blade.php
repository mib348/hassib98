@extends('shopify-app::layouts.default')

@section('styles')
<link rel="stylesheet" href="{{ asset('js/plugins/datatables/dataTables.bootstrap4.css') }}">
<style>
    .js-dataTable-full ul{margin: 0px;padding: 0px;}
    .js-dataTable-full ul li{    list-style-type: none;}
    .loader {
        display:inline-block;
        border: 7px solid #f3f3f3; /* Light grey */
        border-top: 7px solid #ff0000; /* Blue */
        border-radius: 50%;
        width: 35px;
        height: 35px;
        animation: spin 2s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
</style>
@endsection

@section('content')
<div class="container-fluid p-2">
    <h5>Products <div class="loader spinning_status"></div></h5>
    <div class="row">
        <div class="col-md-12">
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
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
    @parent

    <script src="{{ asset('js/plugins/datatables/jquery.dataTables.min.js') }}"></script>
    <script src="{{ asset('js/plugins/datatables/dataTables.bootstrap4.min.js') }}"></script>

    <script type="text/javascript">
    	$(function(){
    	      window.table = jQuery('.js-dataTable-full').DataTable({
    	          pageLength: 10,
    	          lengthMenu: [[5, 10, 20], [5, 10, 20]],
    	          order:[[0, 'desc']],
    	          autoWidth: false
    	      });

    		LoadList();
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
