<?php

namespace Database\Factories;

use App\Models\Vendor;
use App\Models\VendorDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VendorDocument> */
class VendorDocumentFactory extends Factory
{
    protected $model = VendorDocument::class;

    public function definition(): array
    {
        return ['vendor_id' => Vendor::factory(), 'kind' => 'business_license', 'storage_path' => 'test/document.pdf', 'mime' => 'application/pdf', 'size' => 100, 'status' => 'pending'];
    }
}
