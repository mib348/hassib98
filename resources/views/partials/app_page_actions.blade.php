@php
    $primaryAction = $primaryAction ?? null;
    $appPageMenuId = $appPageMenuId ?? 'app-pages-menu';

    /*
     * Keep the app-page menu links in one shared list.
     * Every title bar can include this partial, so new admin pages only need
     * to be added here instead of being copied across many Blade files.
     */
    $appPageLinks = [
        ['label' => 'Location Order Overview', 'path' => '/orders'],
        ['label' => 'Location Products', 'path' => '/location_products'],
        ['label' => 'Stores', 'path' => '/stores'],
        ['label' => 'Locations Revenue', 'path' => '/locations_revenue'],
        ['label' => 'Location Settings', 'path' => '/locations_text'],
        ['label' => 'Kitchen', 'path' => '/kitchen/ADMIN?menu=1'],
        ['label' => 'Drivers', 'path' => '/drivers/ADMIN?menu=1'],
        ['label' => 'RPI Order Details', 'path' => '/order_details_for_rpi/ADMIN?menu=1'],
    ];

    $currentPath = '/'.trim(request()->path(), '/');
    $currentPath = $currentPath === '/' ? '/' : rtrim($currentPath, '/');
@endphp

@if (!empty($primaryAction['label']) && !empty($primaryAction['path']))
    <s-button slot="primary-action" variant="primary" onclick='navigateToPage(@json($primaryAction["path"]))'>{{ $primaryAction['label'] }}</s-button>
@endif

{{-- Use one compact dropdown because Shopify recommends no more than 3 secondary page actions. --}}
<s-button slot="secondary-actions" commandFor="{{ $appPageMenuId }}" accessibilityLabel="Open app page navigation">App pages</s-button>
<s-menu id="{{ $appPageMenuId }}" accessibilityLabel="App page navigation">
    <s-section heading="Admin pages">
        @foreach ($appPageLinks as $appPageLink)
            @php
                $targetPath = '/'.trim(explode('?', $appPageLink['path'], 2)[0], '/');
                $targetPath = $targetPath === '/' ? '/' : rtrim($targetPath, '/');
                $isCurrentPage = $currentPath === $targetPath;
            @endphp

            <s-button
                @if ($isCurrentPage)
                    disabled
                @else
                    onclick='navigateToPage(@json($appPageLink["path"]))'
                @endif
            >{{ $appPageLink['label'] }}</s-button>
        @endforeach
    </s-section>
</s-menu>
