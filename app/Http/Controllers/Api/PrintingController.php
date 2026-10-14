<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArtworkUpload;
use App\Models\Product;
use App\Models\QuotationRequest;
use App\Models\Vendor;
use App\Support\ApiResponse;
use App\Support\Storefront;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PrintingController extends Controller
{
    public function products(Request $request)
    {
        $tenant = Storefront::tenant($request);

        return ApiResponse::page(Product::where('type', 'print')->where('status', 'published')->whereHas('vendor', fn ($q) => $q->where('status', 'active'))->when($tenant, fn ($q) => $q->where('vendor_id', $tenant->id))->with(['vendor:id,name,slug', 'printingSpecification'])->paginate(24));
    }

    public function requestQuote(Request $request)
    {
        $data = $request->validate(['contact_name' => 'required|string|max:160', 'email' => 'required|email|max:190', 'product_type' => 'required|string|max:120', 'quantity' => 'required|integer|min:1|max:1000000', 'size' => 'nullable|string|max:100', 'paper_type' => 'nullable|string|max:100', 'finish' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:5000', 'vendor_slug' => ['nullable', 'string', Rule::exists('vendors', 'slug')->where('status', 'active')], 'artwork' => 'nullable|file|mimes:pdf,png,jpg,jpeg|max:10240']);
        // The receiving vendor is the storefront's verified vendor. vendor_slug is only a marketplace routing hint and can never override it.
        $tenant = Storefront::tenant($request);
        if ($tenant && isset($data['vendor_slug']) && $data['vendor_slug'] !== $tenant->slug) {
            throw ValidationException::withMessages(['vendor_slug' => 'This store can only receive its own quote requests.']);
        }
        $vendorId = $tenant?->id ?? (isset($data['vendor_slug']) ? Vendor::where('slug', $data['vendor_slug'])->where('status', 'active')->value('id') : null);
        $quote = DB::transaction(function () use ($request, $data, $vendorId) {
            $quote = QuotationRequest::create(['reference' => 'QR-'.strtoupper(Str::random(10)), 'customer_id' => $request->user()?->id, 'vendor_id' => $vendorId, 'contact_name' => $data['contact_name'], 'email' => $data['email'], 'product_type' => $data['product_type'], 'quantity' => $data['quantity'], 'size' => $data['size'] ?? null, 'paper_type' => $data['paper_type'] ?? null, 'finish' => $data['finish'] ?? null, 'notes' => $data['notes'] ?? null]);
            if ($request->hasFile('artwork')) {
                $file = $request->file('artwork');
                $path = $file->store('artwork', 'private');
                $quote->artwork()->create(['uploaded_by' => $request->user()?->id, 'disk' => 'private', 'path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(), 'size' => $file->getSize()]);
            }

            return $quote;
        });

        return response()->json(['data' => $quote->load('artwork'), 'message' => 'Quotation request received.'], 201);
    }

    public function quoteStatus(Request $request, QuotationRequest $quotation)
    {
        abort_unless($quotation->customer_id === $request->user()->id, 404);

        return response()->json(['data' => $quotation->load('artwork')]);
    }

    public function customerArtwork(Request $request, QuotationRequest $quotation, ArtworkUpload $artwork)
    {
        abort_unless($quotation->customer_id === $request->user()->id && $artwork->quotation_request_id === $quotation->id, 404);

        return $this->download($artwork);
    }

    public function vendorArtwork(Request $request, string $vendor, QuotationRequest $quotation, ArtworkUpload $artwork)
    {
        $store = $request->attributes->get('vendor');
        abort_unless($quotation->vendor_id === $store->id && $artwork->quotation_request_id === $quotation->id, 404);

        return $this->download($artwork);
    }

    public function vendorQuotes(Request $request, string $vendor)
    {
        $v = $request->attributes->get('vendor');

        return ApiResponse::page($v->quotationRequests()->with('artwork')->latest()->paginate(25));
    }

    public function respond(Request $request, string $vendor, QuotationRequest $quotation)
    {
        $v = $request->attributes->get('vendor');
        abort_unless(in_array($request->attributes->get('vendor_role'), ['owner', 'manager'], true), 403);
        abort_unless($quotation->vendor_id === $v->id, 404);
        $data = $request->validate(['status' => 'required|in:quoted,declined', 'quoted_price' => 'required_if:status,quoted|nullable|numeric|min:0', 'vendor_response' => 'nullable|string|max:5000']);
        $quotation->update([...$data, 'responded_at' => now()]);

        return response()->json(['data' => $quotation]);
    }

    private function download(ArtworkUpload $artwork)
    {
        abort_unless(Storage::disk($artwork->disk)->exists($artwork->path), 404);

        return Storage::disk($artwork->disk)->download($artwork->path, $artwork->original_name, ['Content-Type' => $artwork->mime_type]);
    }
}
