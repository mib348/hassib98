@extends('layouts.app')

@section('styles')
<style>
    /* Background colors for locations */
    .location-1 {
        background-color: #add8e6 !important; /* Light Blue */
    }
    .location-2 {
        background-color: #f5deb3 !important; /* Wheat */
    }
    .location-3 {
        background-color: #90ee90 !important; /* Light Green */
    }
    .location-4 {
        background-color: #dda0dd !important; /* Plum */
    }
    .column-day {
        width: 15%;
    }

    .column-qty {
        width: 4%; /* (100% - 15% for Day) / 8 columns */
    }

    .column-product {
        width: 15%;
    }

</style>
@endsection

@section('content')
<div class="container-fluid p-2">
    <div class="row">
        <div class="col-6">
            <h5>Sushi Catering PreOrders</h5>
        </div>
    </div>
    <div class="row">
        <div class="col-12">
            @foreach($arrData as $location => $days)
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover table-vcenter table-condensed">
                        <thead>
                            <tr>
                                <th colspan="9" class="text-center">{{ $location }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($dates as $date => $day_name)
                                @php
                                    $dayData = $days[$date] ?? [
                                        'day_name' => $day_name,
                                        'products' => []
                                    ];
                                @endphp
                                <tr>
                                    <td class="column-day">{{ $dayData['day_name'] }} ({{ $date }})</td>
                                    @php
                                        $productCount = 0;
                                    @endphp
                                    @if(!empty($dayData['products']))
                                        @foreach($dayData['products'] as $productName => $quantity)
                                            @if($productCount < 4)
                                                <td class="location-{{ $productCount+1 }} text-center column-qty">
                                                    {{ $quantity }}
                                                </td>
                                                <td class="location-{{ $productCount+1 }} column-product">
                                                    {{ $productName }}
                                                </td>
                                                @php
                                                    $productCount++;
                                                @endphp
                                            @else
                                                @break
                                            @endif
                                        @endforeach
                                    @endif

                                    @for($i = $productCount; $i < 4; $i++)
                                        <td class="column-qty"></td>
                                        <td class="column-product"></td>
                                    @endfor
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <br>
            @endforeach
        </div>
    </div>
</div>
@endsection

@section('scripts')
    @parent
    <script>
    </script>
@endsection
