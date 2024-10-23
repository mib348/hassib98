@extends('layouts.app')

@section('styles')
<style>
    /* Background colors for products */
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
    .location-5 {
        background-color: #ffe4e1 !important; /* Misty Rose */
    }
    .location-6 {
        background-color: #e6e6fa !important; /* Lavender */
    }
    .location-7 {
        background-color: #fafad2 !important; /* Light Goldenrod Yellow */
    }
    .location-8 {
        background-color: #d3d3d3 !important; /* Light Gray */
    }
    .location-9 {
        background-color: #f0e68c !important; /* Khaki */
    }
    .location-10 {
        background-color: #e0ffff !important; /* Light Cyan */
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
                                <th colspan="100" class="text-center">{{ $location }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($dates as $date => $day_name)
                                @php
                                    $dayData = $days[$date] ?? [
                                        'day_name' => $day_name,
                                        'products' => []
                                    ];
                                    $productCount = count($dayData['products']);
                                    $rowsNeeded = ceil($productCount / 4) ?: 1;
                                    $productsArray = array_chunk($dayData['products'], 4, true);
                                    $firstRow = true;

                                    // Map product names to fixed color classes
                                    $productColors = [];
                                    $colorClasses = [
                                        'location-1',
                                        'location-2',
                                        'location-3',
                                        'location-4',
                                        'location-5',
                                        'location-6',
                                        'location-7',
                                        'location-8',
                                        'location-9',
                                        'location-10'
                                    ];
                                    $colorIndex = 0;
                                @endphp

                                @if(empty($dayData['products']))
                                    <tr>
                                        <td class="column-day">{{ $dayData['day_name'] }} ({{ $date }})</td>
                                        @for($i = 0; $i < 4; $i++)
                                            <td class="column-qty"></td>
                                            <td class="column-product"></td>
                                        @endfor
                                    </tr>
                                @else
                                    @foreach($productsArray as $rowProducts)
                                        <tr>
                                            @if($firstRow)
                                                <td class="column-day" rowspan="{{ $rowsNeeded }}">{{ $dayData['day_name'] }} ({{ $date }})</td>
                                                @php $firstRow = false; @endphp
                                            @endif
                                            @foreach($rowProducts as $productName => $quantity)
                                                @php
                                                    // Assign a fixed color class to each product
                                                    if (!isset($productColors[$productName])) {
                                                        $productColors[$productName] = $colorClasses[$colorIndex % count($colorClasses)];
                                                        $colorIndex++;
                                                    }
                                                    $colorClass = $productColors[$productName];
                                                @endphp
                                                <td class="{{ $colorClass }} text-center column-qty">
                                                    {{ $quantity }}
                                                </td>
                                                <td class="{{ $colorClass }} column-product">
                                                    {{ $productName }}
                                                </td>
                                            @endforeach
                                            @for($i = count($rowProducts); $i < 4; $i++)
                                                <td class="column-qty"></td>
                                                <td class="column-product"></td>
                                            @endfor
                                        </tr>
                                    @endforeach
                                @endif
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
        setInterval(function() {
            window.location.reload();
        }, 60000); // 1 minute in milliseconds
    </script>
@endsection
