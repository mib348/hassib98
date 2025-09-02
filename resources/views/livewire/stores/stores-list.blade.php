<div x-data="{store_id: $wire.entangle('store_id').live}">
    

    <!-- Add Store Modal -->
    <div class="modal fade" id="addStoreModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form wire:submit.prevent="saveStore">
                    <div class="modal-header">
                        <h5 class="modal-title" x-text="store_id ? 'Edit Store' : 'Add Store'"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" wire:click="resetForm"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Store Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" wire:model="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Locations</label>
                            <div x-data="locationsDropdown()"
                                 x-init="$watch('localSelectedLocations', () => $wire.selectedLocations = localSelectedLocations)">

                                <!-- Display selected locations as badges -->
                                <div class="mb-2" x-show="localSelectedLocations && localSelectedLocations.length > 0">
                                    <template x-for="location in localSelectedLocations" :key="location">
                                        <span x-show="location && location.trim() !== ''" class="badge bg-secondary me-1 mb-1">
                                            <span x-text="location"></span>
                                            <button type="button" 
                                                    class="btn-close btn-close-white ms-1" 
                                                    style="font-size: 0.65rem;"
                                                    @click="removeLocation(location)">
                                            </button>
                                        </span>
                                    </template>
                                </div>

                                <!-- Dropdown wrapper with proper positioning -->
                                <div class="dropdown">
                                    <!-- Custom dropdown button that shows selected locations count -->
                                    <button type="button" 
                                            class="btn btn-outline-secondary dropdown-toggle w-100 text-start" 
                                            @click.prevent="open = !open"
                                            @click.away="open = false">
                                        <span x-show="!localSelectedLocations || localSelectedLocations.filter(l => l && l.trim() !== '').length === 0">Select Locations</span>
                                        <span x-show="localSelectedLocations && localSelectedLocations.filter(l => l && l.trim() !== '').length > 0" 
                                              x-text="localSelectedLocations.filter(l => l && l.trim() !== '').length + ' location(s) selected'"></span>
                                    </button>
                                    
                                    <!-- Dropdown menu with search and checkboxes -->
                                    <div class="dropdown-menu w-100" 
                                         :class="{ 'show': open }"
                                         style="max-height: 300px; overflow-y: auto;">
                                        
                                        <!-- Search input inside dropdown -->
                                        <div class="px-3 py-2" @click.stop>
                                            <div class="input-group input-group-sm">
                                                <input type="text" 
                                                       class="form-control" 
                                                       placeholder="Search locations..."
                                                       x-model="search">
                                                <button class="btn btn-outline-secondary" 
                                                        type="button" 
                                                        x-show="search !== ''"
                                                        @click="search = ''"
                                                        title="Clear search">
                                                    <i class="fa fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="dropdown-divider"></div>
                                        
                                        <!-- Select/Deselect All buttons -->
                                        <div class="px-3 py-2 d-flex gap-2" @click.stop>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-primary"
                                                    @click="selectAll()">
                                                Select All
                                            </button>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-secondary"
                                                    @click="clearAll()">
                                                Clear All
                                            </button>
                                        </div>
                                        
                                        <div class="dropdown-divider"></div>
                                        
                                        <!-- Location checkboxes -->
                                        <div style="max-height: 200px; overflow-y: auto;">
                                            @foreach($arrLocations as $location)
                                                <div class="dropdown-item" 
                                                     x-show="filteredLocations().includes('{{ $location->name }}')"
                                                     @click.stop>
                                                    <div class="form-check">
                                                        <input class="form-check-input" 
                                                               type="checkbox" 
                                                               value="{{ $location->name }}"
                                                               :checked="isSelected('{{ $location->name }}')"
                                                               @change="toggleLocation('{{ $location->name }}')"
                                                               id="location_{{ $location->id }}">
                                                        <label class="form-check-label w-100" 
                                                               for="location_{{ $location->id }}"
                                                               style="cursor: pointer;">
                                                            {{ $location->name }}
                                                        </label>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                        
                                        <!-- No results message -->
                                        <div class="px-3 py-2 text-muted text-center"
                                             x-show="filteredLocations().length === 0">
                                            No locations found
                                        </div>
                                    </div>
                                </div>
                                
                                
                            </div>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" wire:model="is_active" value="Y" :checked="$wire.is_active === 'Y'">
                            <label class="form-check-label">Is active</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" wire:click="resetForm">Cancel</button>
                        <button type="submit" class="btn btn-success" data-bs-dismiss="modal" x-on:click="LoadList()">Save Store</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>

<script>
function locationsDropdown() {
    return {
        open: false,
        search: '',
        localSelectedLocations: @entangle('selectedLocations'),
        availableLocations: @json($arrLocations->pluck('name')->toArray()),
        
        init() {
            // Initialize with Livewire's selectedLocations value - ensure it's actually an array
            if (Array.isArray(this.localSelectedLocations)) {
                // Filter out any empty or null values
                this.localSelectedLocations = this.localSelectedLocations.filter(location => location && location.trim() !== '');
            } else {
                this.localSelectedLocations = [];
            }
            console.log('Dropdown initialized with:', this.localSelectedLocations);
            
            // Listen for Livewire events to update selected locations
            Livewire.on('locationsUpdated', (locations) => {
                console.log('Received locationsUpdated event:', locations);
                if (Array.isArray(locations)) {
                    // Filter out any empty or null values
                    this.localSelectedLocations = locations.filter(location => location && location.trim() !== '');
                } else {
                    this.localSelectedLocations = [];
                }
            });
        },
        
        toggleLocation(location) {
            const index = this.localSelectedLocations.indexOf(location);
            if (index > -1) {
                this.localSelectedLocations.splice(index, 1);
            } else {
                this.localSelectedLocations.push(location);
            }
            console.log('Selected locations after toggle:', this.localSelectedLocations);
        },
        
        isSelected(location) {
            return this.localSelectedLocations.includes(location);
        },
        
        selectAll() {
            this.localSelectedLocations = [...this.availableLocations];
        },
        
        clearAll() {
            this.localSelectedLocations = [];
        },
        
        removeLocation(location) {
            this.localSelectedLocations = this.localSelectedLocations.filter(l => l !== location);
            // Also filter out any empty values after removal
            this.localSelectedLocations = this.localSelectedLocations.filter(l => l && l.trim() !== '');
        },
        
        filteredLocations() {
            if (this.search === '') return this.availableLocations;
            return this.availableLocations.filter(location => 
                location.toLowerCase().includes(this.search.toLowerCase())
            );
        }
    }
}
</script>
