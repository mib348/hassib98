<?php

namespace App\Livewire\Stores;

use App\Models\Locations;
use App\Models\Stores;
use App\Models\StoreLocations;
use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StoresList extends Component
{
    public $name = '';
    public $is_active = 'Y';
    public $store_id = null;
    public $selectedLocations = []; // Array to hold selected location names

    public function saveStore(){
        $this->validate(['name' => 'required|string']);

        // Debug: log what we're trying to save
        logger('Saving store with locations:', [
            'name' => $this->name,
            'selectedLocations' => $this->selectedLocations
        ]);

        // Normalize is_active to strict 'Y' or 'N' regardless of how the checkbox binds
        $isActiveYN = (\in_array($this->is_active, ['Y', 'y', 'on', 1, '1', true], true)) ? 'Y' : 'N';

        if ($this->store_id) {
            // Update existing store
            $store = Stores::find($this->store_id);
            $store->update([
                'name' => $this->name,
                'is_active' => $isActiveYN
            ]);
        } else {
            // Create new store
            $store = Stores::create([
                'name' => ucwords($this->name),
                'uuid' => strtoupper(Str::random(8)), // Generate 8-character short UUID
                'is_active' => $isActiveYN
            ]);
        }

        // Update store locations - delete existing and create new ones
        StoreLocations::where('store_id', $store->id)->delete();
        
        // Add selected locations to the store
        foreach ($this->selectedLocations as $locationName) {
            StoreLocations::create([
                'store_id' => $store->id,
                'location' => $locationName
            ]);
        }

        $this->resetForm();
        $this->dispatch('reload-list');
    }

    #[On('editStore')]
    public function editStore($storeId){
        // First reset the form to clear any previous state
        $this->resetForm();
        
        $store = Stores::find($storeId);
        if (!$store) {
            return;
        }
        $this->store_id = $store->id;
        $this->name = $store->name;
        $this->is_active = $store->is_active; // This will be 'Y' or 'N' from the database
        
        // Load the existing locations for this store
        $this->selectedLocations = StoreLocations::where('store_id', $storeId)
            ->pluck('location')
            ->toArray();
        
        // Dispatch event to update Alpine.js component with selected locations
        $this->dispatch('locationsUpdated', $this->selectedLocations);
        
        // Dispatch browser event to open modal after data is loaded
        $this->dispatch('open-modal');
    }

    #[On('deleteStore')]
    public function deleteStore($storeId){
        $store = Stores::find($storeId);
        if (!$store) {
            return;
        }
        
        // Delete associated store locations first
        StoreLocations::where('store_id', $storeId)->delete();
        
        // Then delete the store
        $store->delete();

        $this->dispatch('reload-list');
    }

    #[On('resetAndOpenModal')]
    public function resetAndOpenModal()
    {
        $this->resetForm();
        $this->dispatch('open-modal');
    }
    
    public function resetForm()
    {
        $this->name = '';
        $this->is_active = 'Y';
        $this->store_id = null;
        $this->selectedLocations = [];
        
        // Dispatch event to clear Alpine.js component locations
        $this->dispatch('locationsUpdated', []);
        
        // Also directly reset the property to ensure it's clean
        $this->reset('selectedLocations');
    }

    public function getStoresList(Request $request)
    {
        $html = "";
        
        $arrStores = Stores::orderBy('name', 'asc')->get();
        
        // Ensure all stores have UUIDs - generate for any that don't
        foreach ($arrStores as $store) {
            if (empty($store->uuid)) {
                $store->update(['uuid' => strtoupper(Str::random(8))]);
            }
        }

        if($arrStores->isEmpty()) {
            $html = "<tr><td colspan='3' class='text-center'>No stores found.</td></tr>";
        }
        else
            foreach ($arrStores as $arrStore) {
                $storeId = $arrStore->id;
                
                // Get locations for this store
                $locations = StoreLocations::where('store_id', $storeId)->pluck('location')->toArray();
                $locationsDisplay = !empty($locations) ? implode(', ', $locations) : '<span class="text-muted">No locations assigned</span>';
                $badgeClass = ($arrStore->is_active == "Y") ? "bg-success" : "bg-danger";
                $badgeText = ($arrStore->is_active == "Y") ? "Enabled" : "Disabled";

                $html .= "<tr data-id='" . $storeId . "'>";
                $html .= "<td>" . $arrStore->uuid . "</td>";
                $html .= "<td>" . $arrStore->name . " <span class='badge $badgeClass'>$badgeText</span></td>";
                $html .= "<td>" . $locationsDisplay . "</td>";
                $html .= "<td>";
                
                // Action buttons (handled via delegated JS to work with DataTables)
                $html .= "<button type='button' class='btn btn-sm btn-warning edit_button'><i class='fa fa-pen-to-square'></i></button>";
                $html .= " <button type='button' class='btn btn-sm btn-danger delete_button'><i class='fa fa-trash'></i></button>";
                
                // Only show kitchen and drivers buttons if UUID exists
                if (!empty($arrStore->uuid)) {
                    // Kitchen button group: open + share
                    $kitchenUrl = route('kitchen.display', $arrStore->uuid);
                    $html .= " <div class='btn-group me-1' role='group'>";
                    $html .= "<a href='" . $kitchenUrl . "' target='_blank' class='btn btn-sm btn-primary kitchen_btn' title='Open Kitchen'><i class='fa fa-kitchen-set'></i></a>";
                    $html .= "<button type='button' class='btn btn-sm btn-outline-secondary share-link-btn' data-href='" . $kitchenUrl . "' title='Copy link'><i class='fa fa-share'></i></button>";
                    $html .= "</div>";

                    // Drivers button group: open + share
                    $driversUrl = route('drivers.display', $arrStore->uuid);
                    $html .= " <div class='btn-group me-1' role='group'>";
                    $html .= "<a href='" . $driversUrl . "' target='_blank' class='btn btn-sm btn-primary drivers_btn' title='Open Drivers'><i class='fa fa-truck-fast'></i></a>";
                    $html .= "<button type='button' class='btn btn-sm btn-outline-secondary share-link-btn' data-href='" . $driversUrl . "' title='Copy link'><i class='fa fa-share'></i></button>";
                    $html .= "</div>";
                } else {
                    // Show disabled buttons if no UUID
                    // $html .= " <button type='button' class='btn btn-sm btn-secondary' disabled title='UUID required'><i class='fa fa-kitchen-set'></i></button>";
                    // $html .= " <button type='button' class='btn btn-sm btn-secondary' disabled title='UUID required'><i class='fa fa-truck-fast'></i></button>";
                }

                $html .= "</td>";
                $html .= "</tr>";
            }

        return $html;
    }

    public function render()
    {
        $arrLocations = Locations::where('is_active', 'Y')
                ->whereNotIn('name', ['Additional Inventory', 'Default Menu', 'Delivery'])
                ->orderBy('name', 'ASC')
                ->get();
        return view('livewire.stores.stores-list', ['html' => $this->getStoresList(request()), 'arrLocations' => $arrLocations]);
    }
}
