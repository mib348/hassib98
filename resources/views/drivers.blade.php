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
    button::after {
        position: absolute;
        z-index: 100;
        right: 16px;
    }
</style>
@endsection

@section('content')
<div class="container-full p-2">
    <div class="row">
        <div class="col-12 col-sm-6">
            <h5>Sushi Catering PreOrders</h5>
        </div>
    </div>
    <div class="row">
        <div class="col-12">
            <div id="accordion" class="accordion">
                @foreach($arrData as $location => $arrProducts)
                    <div class="accordion-item">
                        <div class="accordion-header" id="heading{{ $loop->index }}">
                            <h5 class="mb-0 ">
                                <button class="accordion-button bg-light d-block text-center fw-bold" data-bs-toggle="collapse" data-bs-target="#collapse{{ $loop->index }}" aria-expanded="@if($loop->first) true @else false @endif" aria-controls="collapse{{ $loop->index }}">
                                    {{ ($loop->index + 1) . ". " . $location }}
                                </button>
                            </h5>
                        </div>

                        <div id="collapse{{ $loop->index }}" class="accordion-collapse collapse @if($loop->first) show @endif" aria-labelledby="heading{{ $loop->index }}" data-bs-parent="#accordion">
                            <div class="accordion-body">
                                <h6>PREORDER</h6>
                                <div class="row">
                                    @php
                                        $productCount = 0;
                                    @endphp
                                    @if(!empty($arrProducts))
                                        @foreach($arrProducts['preorder_slot']['products'] as $productName => $quantity)
                                            @if($productCount < 4)
                                                <div class="col-12 col-sm-6">
                                                    <div class="row">
                                                        <div class="col-4 border border-secondary p-2 location-{{ $productCount+1 }} text-center column-qty">
                                                                {{ $quantity }}
                                                        </div>
                                                        <div class="col-8 border border-secondary p-2 location-{{ $productCount+1 }} text-center column-product">
                                                                {{ $productName }}
                                                        </div>
                                                    </div>
                                                </div>
                                                @php
                                                    $productCount++;
                                                @endphp
                                            @else
                                                @break
                                            @endif
                                        @endforeach
                                    @endif

                                    @for($i = $productCount; $i < 4; $i++)
                                    <div class="col-12 col-sm-6">
                                        <div class="row">
                                            <div class="col-4">
                                                &nbsp;
                                            </div>
                                            <div class="col-8">
                                                &nbsp;
                                            </div>
                                        </div>
                                    </div>
                                    @endfor
                                </div>

                                <h6>PREORDER {{ date("h:i A", strtotime($arrProducts['location_data']['sameday_preorder_end_time'])) . " - " . date("h:i A", strtotime($arrProducts['location_data']['first_additional_inventory_end_time'])) }}</h6>
                                <div class="row">
                                    @php
                                        $productCount = 0;
                                    @endphp
                                    @if(!empty($arrProducts))
                                        @foreach($arrProducts['sameday_preorder_slot']['products'] as $productName => $quantity)
                                            @if($productCount < 4)
                                                <div class="col-12 col-sm-6">
                                                    <div class="row">
                                                        <div class="col-4 border border-secondary p-2 location-{{ $productCount+1 }} text-center column-qty">
                                                                {{ $quantity }}
                                                        </div>
                                                        <div class="col-8 border border-secondary p-2 location-{{ $productCount+1 }} text-center column-product">
                                                                {{ $productName }}
                                                        </div>
                                                    </div>
                                                </div>
                                                @php
                                                    $productCount++;
                                                @endphp
                                            @else
                                                @break
                                            @endif
                                        @endforeach
                                    @endif

                                    @for($i = $productCount; $i < 4; $i++)
                                    <div class="col-12 col-sm-6">
                                        <div class="row">
                                            <div class="col-4">
                                                &nbsp;
                                            </div>
                                            <div class="col-8">
                                                &nbsp;
                                            </div>
                                        </div>
                                    </div>
                                    @endfor
                                </div>

                                <h6>PREORDER {{ date("h:i A", strtotime($arrProducts['location_data']['first_additional_inventory_end_time'])) . " - " . date("h:i A", strtotime($arrProducts['location_data']['second_additional_inventory_end_time'])) }}</h6>
                                <div class="row">
                                    @php
                                        $productCount = 0;
                                    @endphp
                                    @if(!empty($arrProducts))
                                        @foreach($arrProducts['additional_inventory_slot']['products'] as $productName => $quantity)
                                            @if($productCount < 4)
                                                <div class="col-12 col-sm-6">
                                                    <div class="row">
                                                        <div class="col-4 border border-secondary p-2 location-{{ $productCount+1 }} text-center column-qty">
                                                                {{ $quantity }}
                                                        </div>
                                                        <div class="col-8 border border-secondary p-2 location-{{ $productCount+1 }} text-center column-product">
                                                                {{ $productName }}
                                                        </div>
                                                    </div>
                                                </div>
                                                @php
                                                    $productCount++;
                                                @endphp
                                            @else
                                                @break
                                            @endif
                                        @endforeach
                                    @endif

                                    @for($i = $productCount; $i < 4; $i++)
                                    <div class="col-12 col-sm-6">
                                        <div class="row">
                                            <div class="col-4">
                                                &nbsp;
                                            </div>
                                            <div class="col-8">
                                                &nbsp;
                                            </div>
                                        </div>
                                    </div>
                                    @endfor
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
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
