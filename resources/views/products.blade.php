@extends('shopify-app::layouts.default')

@section('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
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
    {{-- <h5>Products <div class="loader spinning_status"></div></h5> --}}
    <h5>Products</h5>
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js" integrity="sha384-BBtl+eGJRgqQAUMxJ7pMwbEyER4l1g+O15P+16Ep7Q9Q+zqX6gSbd85u4mG4QzX+" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
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
