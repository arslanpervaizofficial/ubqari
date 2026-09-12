@php $c = $customer ?? null; @endphp
<div class="flex items-center gap-4 mb-5">
    <img id="image-preview" src="{{ $c && $c->image ? $c->image_url : 'https://ui-avatars.com/api/?name=New&background=1e293b&color=fff&size=128' }}"
         class="w-20 h-20 rounded-full object-cover border">
    <div>
        <label class="block text-sm text-gray-600 mb-1">Photo (optional — default avatar used if skipped)</label>
        <input type="file" name="image" accept="image/*" onchange="document.getElementById('image-preview').src = URL.createObjectURL(this.files[0])" class="text-sm">
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-5">
    <div>
        <label class="block text-sm text-gray-600 mb-1">Name</label>
        <input name="name" value="{{ old('name', $c->name ?? '') }}" required data-label="Name" class="w-full border rounded px-3 py-2">
        @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm text-gray-600 mb-1">Phone</label>
        <input name="phone" value="{{ old('phone', $c->phone ?? '') }}" required data-label="Phone"
               pattern="^[0-9+\-\s]{7,15}$" data-pattern-message="Enter a valid phone number (digits, spaces, + or - only, 7–15 characters)."
               class="w-full border rounded px-3 py-2">
        @error('phone') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm text-gray-600 mb-1">Email</label>
        <input type="email" name="email" value="{{ old('email', $c->email ?? '') }}" class="w-full border rounded px-3 py-2">
        @error('email') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm text-gray-600 mb-1">ID Card Number (CNIC)</label>
        <input name="id_card_number" value="{{ old('id_card_number', $c->id_card_number ?? '') }}" placeholder="12345-1234567-1" maxlength="15"
               pattern="^\d{5}-\d{7}-\d{1}$" data-pattern-message="CNIC must be in the format 12345-1234567-1."
               class="w-full border rounded px-3 py-2">
        @error('id_card_number') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div class="md:col-span-2">
        <label class="block text-sm text-gray-600 mb-1">Address</label>
        <input name="address" value="{{ old('address', $c->address ?? '') }}" class="w-full border rounded px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-gray-600 mb-1">Discount %</label>
        <input type="number" step="0.01" min="0" max="100" name="discount_percent" value="{{ old('discount_percent', $c->discount_percent ?? 0) }}" class="w-full border rounded px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-gray-600 mb-1">Current Address</label>
        <input name="current_address" value="{{ old('current_address', $c->current_address ?? '') }}" class="w-full border rounded px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-gray-600 mb-1">Permanent Address</label>
        <input name="permanent_address" value="{{ old('permanent_address', $c->permanent_address ?? '') }}" class="w-full border rounded px-3 py-2">
    </div>
</div>
