{{--
    AppBridge v4 App Nav Component
    This creates a left-side navigation menu (desktop) or dropdown (mobile)
    Include this partial in your content section: @include('partials.app_nav')
--}}

<s-app-nav>
    {{-- Home route - Location Order Overview --}}
    <s-link href="/orders" rel="home">Location Order Overview</s-link>

    {{-- Main navigation links --}}
    <s-link href="/location_products">Location Products</s-link>
    <s-link href="/stores">Stores</s-link>
    <s-link href="/locations_revenue">Locations Revenue</s-link>
    <s-link href="/locations_text">Location Settings</s-link>
    <s-link href="/kitchen/ADMIN?menu=1">Kitchen</s-link>
    <s-link href="/drivers/ADMIN?menu=1">Drivers</s-link>
    <s-link href="/home_delivery">Home Delivery Overview</s-link>
</s-app-nav>
