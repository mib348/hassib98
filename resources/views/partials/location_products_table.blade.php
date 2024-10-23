<thead>
    <tr id="product-header">
        @if($inventoryType == "preorder")
            <th class="fixed-column day-column">Day</th>
        @else
            <th class="day-column">Day</th>
        @endif
        <th colspan="2">Product 1</th>
        <th colspan="2">Product 2</th>
        <th colspan="2">Product 3</th>
        <th colspan="2">Product 4</th>
        @if($inventoryType == "preorder")
            <th class="fixed-column">Action</th>
        @endif
    </tr>
</thead>
<tbody>
    @foreach(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day)
    <tr class="{{ $day }}">
        @if($inventoryType == "preorder")
            <td class="fixed-column day-column">
        @else
            <td class="day-column">
        @endif
            {{ $day }}
            <input type="hidden" name="day[]" class="day" value="{{ $day }}" />
            <button type="button" class="btn btn-primary btn-sm save-day float-right" data-day="{{ $day }}" data-inventory-type="{{ $inventoryType }}">
                Save
                <div class="spinner-border spinner-border-sm text-danger loading-icon" role="status" data-day="{{ $day }}" data-inventory-type="{{ $inventoryType }}"></div>
            </button>
        </td>
        @for ($i = 1; $i <= 4; $i++)
        <td class="product-cell">
            <select name="nProductId[{{ $day }}][]" class="form-select nProductId">
                <option value="" selected>--- Select Product ---</option>
                @foreach($arrProducts as $arrProduct)
                <option value="{{ $arrProduct['id'] }}">{{ $arrProduct['title'] }}</option>
                @endforeach
            </select>
        </td>
        <td class="quantity-cell d-flex flex-row align-items-center justify-content-between">
            <select name="nQuantity[{{ $day }}][]" class="form-select nQuantity">
                @for ($j = 0; $j <= 8; $j++)
                <option value="{{ $j }}" {{ $j == 8 ? 'selected' : '' }}>{{ $j }}</option>
                @endfor
            </select>

            @if($inventoryType == "preorder")
                {{-- <button type="button" class="btn btn-danger btn-sm remove-product-btn text-white"> --}}
                    <i class="fa-solid fa-trash-can text-danger remove-product-btn ms-1"></i>
                {{-- </button> --}}
            @endif
        </td>
        @endfor

        @if($inventoryType == "preorder")
            <td class="text-center fixed-column action-column">
                <button type="button" class="btn btn-sm btn-primary add_product_btn">
                    <i class="fa-solid fa-plus"></i>
                </button>
            </td>
        @endif
    </tr>
    @endforeach
</tbody>
