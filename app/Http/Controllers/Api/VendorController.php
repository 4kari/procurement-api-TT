<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    /**
     * GET /api/v1/vendors
     * Query params: search, is_active, per_page
     */
    public function index(Request $request): JsonResponse
    {
        $query = Vendor::query()
            ->when($request->query('search'), fn($q, $v) =>
                $q->where(fn($sub) => $sub
                    ->where('name', 'ilike', "%{$v}%")
                    ->orWhere('code', 'ilike', "%{$v}%")
                )
            )
            ->when($request->query('is_active') !== null, fn($q) =>
                $q->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN))
            )
            ->orderBy('name');

        $perPage = min((int) $request->query('per_page', 50), 100);

        return response()->json([
            'success' => true,
            'data'    => $query->paginate($perPage),
        ]);
    }

    /**
     * GET /api/v1/vendors/{id}
     */
    public function show(string $id): JsonResponse
    {
        $vendor = Vendor::findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $vendor,
        ]);
    }

    /**
     * POST /api/v1/vendors
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'          => ['required', 'string', 'max:20', 'unique:vendors,code'],
            'name'          => ['required', 'string', 'max:150'],
            'contact_name'  => ['nullable', 'string', 'max:100'],
            'contact_email' => ['nullable', 'email', 'max:150'],
            'npwp'          => ['nullable', 'string', 'max:30'],
            'is_active'     => ['boolean'],
            'rating'        => ['nullable', 'numeric', 'min:0', 'max:5'],
        ]);

        $vendor = Vendor::create($data);

        return response()->json([
            'success' => true,
            'data'    => $vendor,
            'message' => 'Vendor berhasil ditambahkan',
        ], 201);
    }

    /**
     * PUT /api/v1/vendors/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $vendor = Vendor::findOrFail($id);

        $data = $request->validate([
            'code'          => ['sometimes', 'string', 'max:20', "unique:vendors,code,{$id}"],
            'name'          => ['sometimes', 'string', 'max:150'],
            'contact_name'  => ['nullable', 'string', 'max:100'],
            'contact_email' => ['nullable', 'email', 'max:150'],
            'npwp'          => ['nullable', 'string', 'max:30'],
            'is_active'     => ['boolean'],
            'rating'        => ['nullable', 'numeric', 'min:0', 'max:5'],
        ]);

        $vendor->update($data);

        return response()->json([
            'success' => true,
            'data'    => $vendor->fresh(),
            'message' => 'Vendor berhasil diperbarui',
        ]);
    }

    /**
     * DELETE /api/v1/vendors/{id}  — soft delete
     */
    public function destroy(string $id): JsonResponse
    {
        $vendor = Vendor::findOrFail($id);
        $vendor->delete();

        return response()->json([
            'success' => true,
            'message' => 'Vendor berhasil dinonaktifkan',
        ]);
    }
}