<?php

namespace App\Modules\Administration;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Support\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminMediaController extends Controller
{
    public function image(Request $request, Product $product): StreamedResponse
    {
        if (! $request->routeIs('api.v1.admin.*')) {
            abort_unless($product->available && $product->location()->whereHas('vendor', fn ($q) => $q->where('status', 'approved'))->exists(), 404);
        }
        abort_unless($product->image_path !== null && Storage::disk('local')->exists($product->image_path), 404);

        return Storage::disk('local')->response($product->image_path, 'product-'.$product->id.'.'.pathinfo($product->image_path, PATHINFO_EXTENSION), ['X-Content-Type-Options' => 'nosniff'], 'inline');
    }

    public function uploadImage(Request $request, Product $product): JsonResponse
    {
        AdminQuery::body($request, ['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096']]);
        $path = $request->file('image')->store('product-images', 'local');
        abort_unless(is_string($path), 503, 'Image storage is unavailable.');
        try {
            $old = DB::transaction(function () use ($product, $request, $path): ?string {
                $target = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                $old = $target->image_path;
                $target->forceFill(['image_path' => $path])->save();
                Domain::audit($request->user(), 'product.image_uploaded', $target);

                return $old;
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        if (is_string($old) && str_starts_with($old, 'product-images/') && ! str_contains($old, '..')) {
            Storage::disk('local')->delete($old);
        }

        return response()->json(['data' => AdminData::row($product->fresh()->load('location.vendor'))]);
    }

    public function documents(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorizeVendor($request, $vendor);

        return response()->json(AdminQuery::page($request, VendorDocument::where('vendor_id', $vendor->id), ['vendor_documents.id', 'kind', 'review_note'], ['status' => AdminQuery::enum(['pending', 'approved', 'rejected'])]));
    }

    public function uploadDocument(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorizeVendor($request, $vendor);
        $data = AdminQuery::body($request, ['kind' => ['required', Rule::in(['business_license', 'identity', 'tax_certificate', 'other'])], 'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:10240']]);
        $file = $request->file('document');
        $path = $file->store('vendor-documents', 'local');
        abort_unless(is_string($path), 503, 'Document storage is unavailable.');
        try {
            $document = DB::transaction(function () use ($vendor, $data, $file, $path, $request): VendorDocument {
                $document = VendorDocument::record(['vendor_id' => $vendor->id, 'kind' => $data['kind'], 'storage_path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'status' => 'pending']);
                Domain::audit($request->user(), 'vendor.document_uploaded', $document);

                return $document;
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json(['data' => AdminData::row($document)], 201);
    }

    public function downloadDocument(Request $request, Vendor $vendor, VendorDocument $document): StreamedResponse
    {
        $this->authorizeVendor($request, $vendor);
        abort_unless($document->vendor_id === $vendor->id && Storage::disk('local')->exists($document->storage_path), 404);

        return Storage::disk('local')->download($document->storage_path, 'verification-'.$document->id.'.'.pathinfo($document->storage_path, PATHINFO_EXTENSION), ['Content-Type' => $document->mime, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function reviewDocument(Request $request, Vendor $vendor, VendorDocument $document): JsonResponse
    {
        abort_unless($document->vendor_id === $vendor->id, 404);
        $data = AdminQuery::body($request, ['status' => ['required', Rule::in(['approved', 'rejected'])], 'review_note' => ['required_if:status,rejected', 'nullable', 'string', 'max:500']]);
        DB::transaction(function () use ($request, $document, $data): void {
            $target = VendorDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $target->forceFill($data + ['reviewed_by' => $request->user()->id, 'reviewed_at' => now()])->save();
            Domain::audit($request->user(), 'vendor.document_reviewed', $target, $data);
        }, 3);

        return response()->json(['data' => AdminData::row($document->fresh())]);
    }

    private function authorizeVendor(Request $request, Vendor $vendor): void
    {
        if ($request->routeIs('api.v1.admin.*')) {
            Gate::authorize('administer');
        } else {
            abort_unless($request->user()->hasRole('vendor') && $request->user()->manages($vendor->id), 404);
        }
    }
}
