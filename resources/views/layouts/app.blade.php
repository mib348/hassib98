<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sushi Catering Web App</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Optional custom styles -->
    @if(request()->attributes->get('master_frame'))
        <link rel="stylesheet" href="{{ asset('js/plugins/datatables/dataTables.bootstrap4.css') }}">
        <style>
            /* Let long admin headings wrap while wide tables retain their own
               horizontal scrollbars. Camera/modal overflow remains untouched. */
            .master-admin-frame .accordion-button { flex-wrap: wrap; overflow-wrap: anywhere; gap: .25rem; }
            .master-admin-frame .accordion-button > * { max-width: 100%; white-space: normal; }
            .master-admin-frame h5 { overflow-wrap: anywhere; }
            .master-admin-frame .dataTables_wrapper .pagination { flex-wrap: wrap; }
        </style>
    @endif
    @yield('styles')
</head>
<body @if(request()->attributes->get('master_frame')) class="master-admin-frame" @endif>

    <!-- Main Content Area -->
    <div class="container-fluid mt-4">
        @yield('content')
    </div>

    <!-- Bootstrap JS Bundle (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Optional custom scripts -->
    @yield('scripts')
</body>
</html>
