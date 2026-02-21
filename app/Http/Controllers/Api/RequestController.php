<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Request\StoreRequestRequest;
use App\Http\Requests\Approval\ApproveRequestRequest;
use App\Http\Requests\Approval\RejectRequestRequest;
use App\Http\Requests\Request\ProcureRequestRequest;
use App\Http\Resources\RequestResource;
use App\Http\Resources\ProcurementOrderResource;
use App\Models\Request as ProcurementRequest;
use App\Services\RequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RequestController extends Controller
{
    public function __construct(private readonly RequestService $service) {}

    /**
     * GET /api/v1/requests?status=approved&page=1&per_page=15
     *
     * - EMPLOYEE: sees only own requests
     * - PURCHASING / MANAGER / WAREHOUSE / ADMIN: sees all
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = ProcurementRequest::with(['requester:id,name', 'department:id,name,code'])
            ->withCount('items')
            ->forUser($user)
            ->byStatus($request->query('status'))
            ->when($request->query('department_id'), fn($q, $v) => $q->where('department_id', $v))
            ->when($request->query('priority'), fn($q, $v) => $q->where('priority', $v))
            ->when($request->query('search'), fn($q, $v) =>
                $q->where(fn($sub) => $sub
                    ->where('title', 'ilike', "%{$v}%")
                    ->orWhere('request_number', 'ilike', "%{$v}%")
                )
            )
            ->orderBy($request->query('sort', 'created_at'), $request->query('dir', 'desc'));

        $perPage  = min((int) $request->query('per_page', 15), 100);
        $paginate = $query->paginate($perPage);

        return RequestResource::collection($paginate);
    }

    /**
     * POST /api/v1/requests
     */
    public function store(StoreRequestRequest $request): JsonResponse
    {
        $req = $this->service->create($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'data'    => new RequestResource($req),
            'message' => 'Request berhasil dibuat',
        ], 201);
    }

    /**
     * GET /api/v1/requests/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $req = ProcurementRequest::with([
            'requester:id,name,employee_code',
            'department:id,name,code',
            'items.stock:id,sku,name,quantity,reserved',
            'approvals.approver:id,name,role',
            'procurementOrder.vendor:id,name,code',
            'statusHistory.actor:id,name',
        ])->findOrFail($id);

        // Employee can only see own requests
        if ($request->user()->isEmployee() && $req->requester_id !== $request->user()->id) {
            abort(403, 'Forbidden');
        }

        return response()->json([
            'success' => true,
            'data'    => new RequestResource($req),
        ]);
    }

    /**
     * POST /api/v1/requests/{id}/submit
     */
    public function submit(Request $request, string $id): JsonResponse
    {
        $req  = ProcurementRequest::findOrFail($id);
        $data = $request->validate(['version' => ['required', 'integer']]);

        $updated = $this->service->submit($req, $request->user(), $data['version']);

        return response()->json([
            'success' => true,
            'data'    => new RequestResource($updated),
            'message' => 'Request berhasil diajukan',
        ]);
    }

    /**
     * POST /api/v1/requests/{id}/approve
     */
    public function approve(ApproveRequestRequest $request, string $id): JsonResponse
    {
        $req = ProcurementRequest::findOrFail($id);

        // Manager approves; Purchasing verifies (step 1 handled via /verify)
        $updated = $this->service->approve($req, $request->user(), $request->validated());

        // After approval, check stock availability automatically
        $stockAvailable = $this->service->checkAndMarkStockAvailability($updated->fresh(), $request->user());

        return response()->json([
            'success' => true,
            'data'    => new RequestResource($updated->fresh()),
            'message' => $stockAvailable
                ? 'Request diapprove. Stok tersedia, status diubah ke READY.'
                : 'Request diapprove. Stok tidak tersedia, lanjutkan ke pengadaan.',
        ]);
    }

    /**
     * POST /api/v1/requests/{id}/verify  (Purchasing step)
     */
    public function verify(Request $request, string $id): JsonResponse
    {
        $req  = ProcurementRequest::findOrFail($id);
        $data = $request->validate([
            'notes'   => ['nullable', 'string', 'max:500'],
            'version' => ['required', 'integer'],
        ]);

        $updated = $this->service->verify($req, $request->user(), $data);

        return response()->json([
            'success' => true,
            'data'    => new RequestResource($updated),
            'message' => 'Request berhasil diverifikasi',
        ]);
    }

    /**
     * POST /api/v1/requests/{id}/reject
     */
    public function reject(RejectRequestRequest $request, string $id): JsonResponse
    {
        $req     = ProcurementRequest::findOrFail($id);
        $updated = $this->service->reject($req, $request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'data'    => new RequestResource($updated),
            'message' => 'Request berhasil ditolak',
        ]);
    }

    /**
     * POST /api/v1/requests/{id}/procure
     */
    public function procure(ProcureRequestRequest $request, string $id): JsonResponse
    {
        $req = ProcurementRequest::findOrFail($id);
        $po  = $this->service->procure($req, $request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'data'    => new ProcurementOrderResource($po),
            'message' => 'Purchase Order berhasil dibuat',
        ], 201);
    }

    /**
     * GET /api/v1/requests/{id}/history
     */
    public function history(string $id): JsonResponse
    {
        $req = ProcurementRequest::findOrFail($id);
        $history = $req->statusHistory()->with('actor:id,name,role')->get();

        return response()->json([
            'success' => true,
            'data'    => $history->map(fn($h) => [
                'from_status' => $h->from_status,
                'to_status'   => $h->to_status,
                'changed_by'  => $h->actor?->only(['id', 'name', 'role']),
                'reason'      => $h->reason,
                'created_at'  => $h->created_at,
            ]),
        ]);
    }
}
