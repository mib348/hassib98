@extends('shopify-app::layouts.default')

@section('content')
<div class="container-fluid">
    <!-- You are: (shop domain name) -->
    <p>You are: {{ $shopDomain ?? Auth::user()->name }}</p>
</div>
@endsection

@section('scripts')
    @parent
@endsection
