<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    private function rules(): array
    {
        return ['recipient' => 'required|string|max:160', 'phone' => 'nullable|string|max:32', 'line1' => 'required|string|max:190', 'line2' => 'nullable|string|max:190', 'city' => 'required|string|max:120', 'region' => 'nullable|string|max:120', 'postal_code' => 'nullable|string|max:24', 'country' => 'required|string|size:2', 'is_default' => 'sometimes|boolean'];
    }

    public function index(Request $request)
    {
        return response()->json(['data' => $request->user()->addresses()->latest()->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $address = DB::transaction(function () use ($request, $data) {
            if (($data['is_default'] ?? false) || ! $request->user()->addresses()->exists()) {
                $request->user()->addresses()->update(['is_default' => false]);
                $data['is_default'] = true;
            }

            return $request->user()->addresses()->create($data);
        });

        return response()->json(['data' => $address], 201);
    }

    public function update(Request $request, Address $address)
    {
        abort_unless($address->user_id === $request->user()->id, 404);
        $data = $request->validate($this->rules());
        DB::transaction(function () use ($request, $address, $data) {
            if ($data['is_default'] ?? false) {
                $request->user()->addresses()->update(['is_default' => false]);
            }
            $address->update($data);
        });

        return response()->json(['data' => $address->fresh()]);
    }

    public function destroy(Request $request, Address $address)
    {
        abort_unless($address->user_id === $request->user()->id, 404);
        $address->delete();

        return response()->json(['message' => 'Address removed.']);
    }
}
