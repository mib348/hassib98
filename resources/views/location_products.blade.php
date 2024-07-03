@extends('shopify-app::layouts.default')

@section('styles')
<style>
</style>
@endsection

@section('content')
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
            <form id="location_products_form">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full">
                        <thead>
                            <tr>
                                <th>
                                    Location
                                    <select id="strFilterLocation" name="strFilterLocation" class="form-select">
                                        <option value="" selected>--- Select Location ---</option>
                                        @foreach($arrLocations as $location)
                                        <option value="{{ $location->name }}">{{ $location->name }}</option>
                                        @endforeach
                                    </select>
                                </th>
                                <th colspan="2">Product 1</th>
                                <th colspan="2">Product 2</th>
                                <th colspan="2">Product 3</th>
                                <th colspan="2">Product 4</th>
                            </tr>
                        </thead>
                        <tbody id="table">
                            <tr class="Monday">
                                <td>Monday <input type="hidden" name="day[]" class="day" value="Monday" /></td>
                                <td>
                                    <select name="nProductId[Monday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Monday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Monday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Monday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Monday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Monday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Monday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Monday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                            </tr>
                            <tr class="Tuesday">
                                <td>Tuesday<input type="hidden" name="day[]" class="day" value="Tuesday" /></td>
                                <td>
                                    <select name="nProductId[Tuesday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Tuesday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Tuesday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Tuesday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Tuesday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Tuesday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Tuesday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Tuesday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                            </tr>
                            <tr class="Wednesday">
                                <td>Wednesday<input type="hidden" name="day[]" class="day" value="Wednesday" /></td>
                                <td>
                                    <select name="nProductId[Wednesday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Wednesday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Wednesday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Wednesday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Wednesday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Wednesday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Wednesday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Wednesday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                            </tr>
                            <tr class="Thursday">
                                <td>Thursday<input type="hidden" name="day[]" class="day" value="Thursday" /></td>
                                <td>
                                    <select name="nProductId[Thursday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Thursday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Thursday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Thursday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Thursday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Thursday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Thursday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Thursday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                            </tr>
                            <tr class="Friday">
                                <td>Friday<input type="hidden" name="day[]" class="day" value="Friday" /></td>
                                <td>
                                    <select name="nProductId[Friday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Friday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Friday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Friday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Friday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Friday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Friday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Friday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                            </tr>
                            <tr class="Saturday">
                                <td>Saturday<input type="hidden" name="day[]" class="day" value="Saturday" /></td>
                                <td>
                                    <select name="nProductId[Saturday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Saturday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Saturday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Saturday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Saturday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Saturday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Saturday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Saturday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                            </tr>
                            <tr class="Sunday">
                                <td>Sunday<input type="hidden" name="day[]" class="day" value="Sunday" /></td>
                                <td>
                                    <select name="nProductId[Sunday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Sunday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Sunday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Sunday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Sunday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Sunday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="nProductId[Sunday][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct->product_id }}">{{ $arrProduct->title }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[Sunday][]" class="form-select nQuantity" data-quantity="">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8" selected="selected">8</option>
                                    </select>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="row">
                    <div class="col-1">
                        <button type="button" class="btn btn-primary" id="save_btn">Save</button>
                    </div>
                    <div class="col-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="Y" name="replace_data_cb" id="replace_data_cb">
                            <label class="form-check-label" for="replace_data_cb">
                                Replace current default quantity
                            </label>
                          </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@section('scripts')
    @parent


    <script>
        // Assuming 'app' is already initialized and available
        // var actions = window['app-bridge'].actions;
        var Button = actions.Button;
        var TitleBar = actions.TitleBar;
        var Redirect = actions.Redirect; // Ensure Redirect is defined

        // Create a button for 'Products'
        var productsButton = Button.create(app, { label: 'Products' });
        productsButton.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/products');
            // Add your logic for when the 'Products' button is clicked
        });

        // Create a button for 'Orders'
        var ordersButton = Button.create(app, { label: 'Location Order Overview' });
        ordersButton.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/orders');
            // Add your logic for when the 'Orders' button is clicked
        });

        // Create a button for 'Operation'
        var operationdays = Button.create(app, { label: 'Operation Days' });
        operationdays.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/operationdays');
            // Add your logic for when the 'Operation' button is clicked
        });


        // Create a button for 'Locations Revenue'
        var locations_revenue = Button.create(app, { label: 'Locations Revenue' });
        locations_revenue.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/locations_revenue');
            // Add your logic for when the 'Locations Revenue' button is clicked
        });

        // Create a button for 'Locations Text'
        var locations_text = Button.create(app, { label: 'Locations Text' });
        locations_text.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/locations_text');
            // Add your logic for when the 'Location Products' button is clicked
        });

        // Update the title bar with the new buttons
        var titleBar = TitleBar.create(app, {
            title: 'Location Products',
            buttons: {
                primary: productsButton,
                secondary: [ordersButton, operationdays, locations_revenue, locations_text]
            },
        });
    </script>

    <script type="text/javascript">
    	$(function(){
    	    //   window.table = jQuery('.js-dataTable-full').DataTable({
    	    //       pageLength: 10,
    	    //       lengthMenu: [[5, 10, 20], [5, 10, 20]],
    	    //       order:[[0, 'desc']],
            //       columnDefs: [{ orderable: false, targets: 0 }, { orderable: false, targets: 1 }],
    	    //       autoWidth: false
    	    //   });

              $(document).on('change', '#strFilterLocation', function(e){
                LoadList();
              });

              $(document).on('click', '#save_btn', function(e){
                if($("#strFilterLocation").val() == ''){
                    alert('Please Select Location');
                    return false;
                }

                $.ajax({
                    url:"{{ route('location_products.store') }}",
                    async:false,
                    type:"POST",
                    // data: {
                    //     "_token": "{{ csrf_token() }}",
                    //     "strFilterLocation": $("#strFilterLocation").val(),
                    //     "strFilterDate": $("#strFilterDate").val(),
                    //     "location_products_form": $("#location_products_form").serialize()
                    // },
                    data: "_token={{ csrf_token() }}&"+$("#location_products_form").serialize(),
                    cache:false,
                    dataType:"json",
                    success:function(data){
                        alert('Location Products Data Saved Successfully');
                    },
                    error: function (request, status, error) {
                        alert('error saving Location Products Data');
                    }
                });
              });

    		//LoadList();
        });

        function LoadList(){
            $.ajax({
                url:"{{ route('getLocationsProductsJSON') }}",
                type:"GET",
                data: {
                    "_token": "{{ csrf_token() }}",
                    "strFilterLocation": $("#strFilterLocation").val(),
                },
                cache:false,
                dataType:"json",
                success:function(data){
                    // Clear existing selections
                    $("#table select.nProductId").val('');
                    $("#table select.nQuantity").val(8);

                    if(data && data.length > 0){
                        // Group data by day for easier access
                        const dataByDay = data.reduce((acc, item) => {
                            acc[item.day] = acc[item.day] || [];
                            acc[item.day].push(item);
                            return acc;
                        }, {});

                        for (const day in dataByDay) {
                            const dayData = dataByDay[day];
                            let productIndex = 0;

                            dayData.forEach(item => {
                                // Find the correct product dropdown
                                const productSelect = $("#table tr." + day + " .nProductId").eq(productIndex);
                                const quantitySelect = $("#table tr." + day + " .nQuantity").eq(productIndex);

                                // Set selected values
                                productSelect.val(item.product_id);
                                quantitySelect.val(item.quantity);

                                productIndex++;
                            });
                        }
                    } else {
                        // Handle case where no data is returned
                        // You might want to display a message to the user.
                    }
                },
                error: function (request, status, error) {
                    console.error('Error fetching Location Products Data:', error);
                    // Display a user-friendly error message
                    alert("An error occurred while loading product data.");
                }
            });
        }
    </script>
@endsection
