<?php

namespace App\Services;

use App\Events\RequestStatusChanged;
use App\Models\Approval;
use App\Models\ProcurementOrder;
use App\Models\ProcurementOrderItem;
// use App\Models\Request;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use App\Models\Request as ProcurementRequest;

class RequestService
{
    // ── Create ────────────────────────────────────────────────
    public function create(User $requester, array $data): ProcurementRequest
    {
        return DB::transaction(function () use ($requester, $data) {
            $request = ProcurementRequest::create([
                'request_number' => $this->generateNumber(),
                'requester_id'   => $requester->id,
                'department_id'  => $requester->department_id,
                'title'          => $data['title'],
                'description'    => $data['description'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'priority'       => $data['priority'] ?? 2,
                'required_date'  => $data['required_date'] ?? null,
                'status'         => ProcurementRequest::STATUS_DRAFT,
                'version'        => '1',
            ]);

            foreach ($data['items'] as $item) {
                $request->items()->create([
                    'item_name'       => $item['item_name'],
                    'category'        => $item['category'] ?? 'OTHER',
                    'quantity'        => $item['quantity'],
                    'unit'            => $item['unit'],
                    'estimated_price' => $item['estimated_price'] ?? null,
                    'stock_id'        => $item['stock_id'] ?? null,
                    'notes'           => $item['notes'] ?? null,
                ]);
            }

            return $request->load('items', 'requester', 'department');
        });
    }

    // ── Submit ────────────────────────────────────────────────
    public function submit(ProcurementRequest $request, User $actor, int $version): ProcurementRequest
    {
        $this->assertVersion($request, $version);
        $this->assertTransition($request, ProcurementRequest::STATUS_SUBMITTED);

        return DB::transaction(function () use ($request, $actor) {
            $this->setCurrentUser($actor);

            // Create the verification slot for Purchasing
            Approval::create([
                'request_id'  => $request->id,
                'approver_id' => $this->findPurchasingUser()->id,
                'step'        => Approval::STEP_VERIFY,
            ]);

            $request->update([
                'status'       => ProcurementRequest::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ]);

            event(new RequestStatusChanged($request, ProcurementRequest::STATUS_DRAFT, ProcurementRequest::STATUS_SUBMITTED, $actor));

            return $request->fresh();
        });
    }

    // ── Verify (Purchasing step) ──────────────────────────────
    public function verify(ProcurementRequest $request, User $actor, array $data): ProcurementRequest
    {
        $this->assertVersion($request, $data['version']);
        $this->assertTransition($request, ProcurementRequest::STATUS_VERIFIED);

        return DB::transaction(function () use ($request, $actor, $data) {
            $this->setCurrentUser($actor);

            $approval = $this->findOrFailPendingApproval($request, Approval::STEP_VERIFY);

            $approval->update([
                'action'   => Approval::ACTION_VERIFY,
                'notes'    => $data['notes'] ?? null,
                'acted_at' => now(),
            ]);

            // Create the approval slot for Manager
            Approval::create([
                'request_id'  => $request->id,
                'approver_id' => $this->findManagerUser()->id,
                'step'        => Approval::STEP_APPROVE,
            ]);

            $request->update(['status' => ProcurementRequest::STATUS_VERIFIED]);
            event(new RequestStatusChanged($request, ProcurementRequest::STATUS_SUBMITTED, ProcurementRequest::STATUS_VERIFIED, $actor));

            return $request->fresh();
        });
    }

    // ── Approve (Manager step) ────────────────────────────────
    public function approve(ProcurementRequest $request, User $actor, array $data): ProcurementRequest
    {
        // 1. Cek optimistic lock
        if ($request->version !== $data['version']) {
            throw new ConflictHttpException(
                'Data telah diubah. Silakan refresh dan coba lagi.'
            );
        }

        // 2. Cek transisi status valid
        if (!$request->canTransitionTo(ProcurementRequest::STATUS_APPROVED)) {
            throw new UnprocessableEntityHttpException(
                "Tidak bisa approve dari status {$request->status}. Status harus VERIFIED."
            );
        }

        // 3. Simpan approval dan ubah status ke APPROVED
        DB::transaction(function () use ($request, $actor, $data) {
            $this->setCurrentUser($actor);

            $request->update([
                'status' => ProcurementRequest::STATUS_APPROVED,
            ]);
            $existingApproval = Approval::where('request_id', $request->id)
                ->where('step', Approval::STEP_APPROVE)
                ->first();
            if (!$existingApproval) {
                Approval::create([
                    'request_id'  => $request->id,
                    'approver_id' => $actor->id,
                    'step'        => Approval::STEP_APPROVE,
                    'action'      => Approval::ACTION_APPROVE,
                    'notes'       => $data['notes'] ?? null,
                    'acted_at'    => now(),
                ]);
            }

            event(new RequestStatusChanged(
                $request->fresh(),
                ProcurementRequest::STATUS_VERIFIED,      // from
                ProcurementRequest::STATUS_APPROVED,  // to
                $actor
            ));
        });

        // 4. Cek ketersediaan stok setelah APPROVED
        //    - Semua stok tersedia  → otomatis READY
        //    - Ada stok tidak cukup → otomatis IN_PROCUREMENT
        $allAvailable = $this->checkAndMarkStockAvailability(
            $request->fresh()->load('items.stock'),
            $actor
        );

        if (!$allAvailable) {
            DB::transaction(function () use ($request, $actor) {
                $this->setCurrentUser($actor);

                $request->update([
                    'status' => ProcurementRequest::STATUS_IN_PROCUREMENT,
                ]);

                event(new RequestStatusChanged(
                    $request->fresh(),
                    ProcurementRequest::STATUS_APPROVED,      // from
                    ProcurementRequest::STATUS_IN_PROCUREMENT,  // to
                    $actor
                ));
            });
        }

        return $request->fresh()->load('items', 'requester', 'department');
    }

    // ── Reject ────────────────────────────────────────────────
    public function reject(ProcurementRequest $request, User $actor, array $data): ProcurementRequest
    {
        $this->assertVersion($request, $data['version']);

        // Reject allowed from SUBMITTED or VERIFIED
        if (!in_array($request->status, [ProcurementRequest::STATUS_SUBMITTED, ProcurementRequest::STATUS_VERIFIED])) {
            throw new UnprocessableEntityHttpException('Request cannot be rejected from status: '.$request->status);
        }

        $step = $request->status === ProcurementRequest::STATUS_SUBMITTED
            ? Approval::STEP_VERIFY
            : Approval::STEP_APPROVE;

        return DB::transaction(function () use ($request, $actor, $data, $step) {
            $this->setCurrentUser($actor);

            $approval = $this->findOrFailPendingApproval($request, $step);
            $approval->update([
                'action'   => Approval::ACTION_REJECT,
                'notes'    => $data['reason'],
                'acted_at' => now(),
            ]);

            $request->update(['status' => ProcurementRequest::STATUS_REJECTED]);
            event(new RequestStatusChanged($request, $request->status, ProcurementRequest::STATUS_REJECTED, $actor, $data['reason']));

            return $request->fresh();
        });
    }

    // ── Procure ───────────────────────────────────────────────
    public function procure(ProcurementRequest $request, User $actor, array $data): ProcurementOrder
    {
        $this->assertVersion($request, $data['version']);
        $this->assertTransition($request, ProcurementRequest::STATUS_IN_PROCUREMENT);

        return DB::transaction(function () use ($request, $actor, $data) {
            $this->setCurrentUser($actor);

            $po = ProcurementOrder::create([
                'po_number'   => $this->generatePoNumber(),
                'request_id'  => $request->id,
                'vendor_id'   => $data['vendor_id'],
                'created_by'  => $actor->id,
                'expected_at' => $data['expected_at'] ?? null,
                'notes'       => $data['notes'] ?? null,
                'status'      => ProcurementOrder::STATUS_PENDING,
            ]);

            $total = 0;
            foreach ($data['items'] as $item) {
                $po->items()->create([
                    'request_item_id' => $item['request_item_id'] ?? null,
                    'item_name'       => $item['item_name'],
                    'quantity'        => $item['quantity'],
                    'unit_price'      => $item['unit_price'],
                ]);
                $total += $item['quantity'] * $item['unit_price'];
            }

            $po->update(['total_amount' => $total]);
            $request->update(['status' => ProcurementRequest::STATUS_IN_PROCUREMENT]);
            event(new RequestStatusChanged($request, ProcurementRequest::STATUS_APPROVED, ProcurementRequest::STATUS_IN_PROCUREMENT, $actor));

            return $po->load('items', 'vendor', 'request');
        });
    }
    
    // ── Check stock availability (called after APPROVED) ──────
    public function checkAndMarkStockAvailability(ProcurementRequest $request, User $actor): bool
    {
        $allAvailable = true;

        foreach ($request->items as $item) {
            \Log::info('Cek stok item', [
                'item_name'   => $item->item_name,
                'stock_id'    => $item->stock_id,
                'qty_needed'  => $item->quantity,
                'stock_qty'   => $item->stock?->quantity,
                'stock_avail' => $item->stock?->availableQty(),
            ]);
            if (!$item->stock_id) {
                $allAvailable = false;
                break;
            }

            $stock = Stock::find($item->stock_id);
            if (!$stock || $stock->availableQty() < $item->quantity) {
                $allAvailable = false;
                break;
            }
        }

        if ($allAvailable) {
            DB::transaction(function () use ($request, $actor) {
                $this->setCurrentUser($actor);

                // Reserve stock for each item atomically
                foreach ($request->items as $item) {
                    $stock = Stock::find($item->stock_id);
                    $success = DB::selectOne('SELECT fn_reserve_stock(?, ?, ?) AS ok', [
                        $item->stock_id,
                        $item->quantity,
                        $stock->version,
                    ])->ok;

                    if (!$success) {
                        throw new ConflictHttpException('Stock insufficient after lock for item: '.$item->item_name);
                    }
                }

                $request->update(['status' => ProcurementRequest::STATUS_READY]);
                event(new RequestStatusChanged($request, ProcurementRequest::STATUS_APPROVED, ProcurementRequest::STATUS_READY, $actor));
            });
        }

        return $allAvailable;
    }

    // ── Helpers ───────────────────────────────────────────────
    private function assertVersion(ProcurementRequest $request, int $version): void
    {
        if ($request->version !== $version) {
            throw new ConflictHttpException(
                'Data has been modified by another user. Please refresh and try again.'
            );
        }
    }

    private function assertTransition(ProcurementRequest $request, string $to): void
    {
        if (!$request->canTransitionTo($to)) {
            throw new UnprocessableEntityHttpException(
                "Cannot transition from '{$request->status}' to '{$to}'."
            );
        }
    }

    private function findOrFailPendingApproval(ProcurementRequest $request, int $step): Approval
    {
        $approval = $request->approvals()
            ->where('step', $step)
            ->whereNull('acted_at')
            ->first();

        if (!$approval) {
            throw new UnprocessableEntityHttpException("No pending approval found for step {$step}.");
        }

        return $approval;
    }

    /** Set PostgreSQL session variable so the trigger can log the acting user */
    private function setCurrentUser(User $user): void
    {
        DB::statement("SET LOCAL app.current_user_id = '{$user->id}'");
    }

    private function generateNumber(): string
    {
        $year  = now()->year;
        $count = ProcurementRequest::withTrashed()->whereYear('created_at', $year)->count() + 1;
        return sprintf('REQ-%d-%06d', $year, $count);
    }

    private function generatePoNumber(): string
    {
        $year  = now()->year;
        $count = ProcurementOrder::withTrashed()->whereYear('created_at', $year)->count() + 1;
        return sprintf('PO-%d-%06d', $year, $count);
    }

    private function findPurchasingUser(): User
    {
        return User::where('role', User::ROLE_PURCHASING)->where('is_active', true)->firstOrFail();
    }

    private function findManagerUser(): User
    {
        return User::where('role', User::ROLE_PURCHASING_MANAGER)->where('is_active', true)->firstOrFail();
    }

    public function receive(ProcurementRequest $request, User $actor, array $data): ProcurementRequest
    {
        if ($request->version !== $data['version']) {
            throw new ConflictHttpException('Data telah diubah. Silakan refresh dan coba lagi.');
        }

        if (!$request->canTransitionTo(ProcurementRequest::STATUS_READY)) {
            throw new UnprocessableEntityHttpException(
                "Tidak bisa receive dari status {$request->status}. Status harus IN_PROCUREMENT."
            );
        }

        DB::statement("SET LOCAL app.current_user_id = '{$actor->id}'");

        $request->update([
            'status' => ProcurementRequest::STATUS_READY,
        ]);

        event(new RequestStatusChanged(
                $request->fresh(),
                ProcurementRequest::STATUS_IN_PROCUREMENT,      // from
                ProcurementRequest::STATUS_READY,  // to
                $actor
            ));

        return $request->fresh()->load('items', 'requester', 'department');
    }

    public function complete(ProcurementRequest $request, User $actor, array $data): ProcurementRequest
    {
        if ($request->version !== $data['version']) {
            throw new ConflictHttpException('Data telah diubah. Silakan refresh dan coba lagi.');
        }

        if (!$request->canTransitionTo(ProcurementRequest::STATUS_COMPLETED)) {
            throw new UnprocessableEntityHttpException(
                "Tidak bisa complete dari status {$request->status}. Status harus READY."
            );
        }

        DB::statement("SET LOCAL app.current_user_id = '{$actor->id}'");

        $request->update([
            'status'       => ProcurementRequest::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        event(new RequestStatusChanged(
                $request->fresh(),
                ProcurementRequest::STATUS_READY,      // from
                ProcurementRequest::STATUS_COMPLETED,  // to
                $actor
            ));

        return $request->fresh()->load('items', 'requester', 'department');
    }
}
