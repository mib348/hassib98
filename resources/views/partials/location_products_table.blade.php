<thead>
    <tr>
        <th>
            Day
            <!-- Optional: You can add a save button for the entire table here if needed -->
        </th>
        <th colspan="2">Product 1</th>
        <th colspan="2">Product 2</th>
        <th colspan="2">Product 3</th>
        <th colspan="2">Product 4</th>
    </tr>
</thead>
<tbody>
    @foreach(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day)
    <tr class="{{ $day }}">
        <td>
            {{ $day }}
            <input type="hidden" name="day[]" class="day" value="{{ $day }}" />
            <button type="button" class="btn btn-primary btn-sm save-day float-right" data-day="{{ $day }}" data-inventory-type="{{ $inventoryType }}">
                Save
                <div class="spinner-border spinner-border-sm text-danger loading-icon" role="status" data-day="{{ $day }}" data-inventory-type="{{ $inventoryType }}"></div>
            </button>
        </td>
        @for ($i = 1; $i <= 4; $i++)
        <td>
            <select name="nProductId[{{ $day }}][]" class="form-select nProductId" data-product="">
                <option value="" selected>--- Select Product ---</option>
                @foreach($arrProducts as $arrProduct)
                <option value="{{ $arrProduct['id'] }}">{{ $arrProduct['title'] }}</option>
                @endforeach
            </select>
        </td>
        <td>
            <select name="nQuantity[{{ $day }}][]" class="form-select nQuantity" data-quantity="">
                @for ($j = 0; $j <= 8; $j++)
                <option value="{{ $j }}" {{ $j == 8 ? 'selected' : '' }}>{{ $j }}</option>
                @endfor
            </select>
        </td>
        @endfor
    </tr>
    @endforeach
</tbody>
