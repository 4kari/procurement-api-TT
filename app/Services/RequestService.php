<?php

namespace App\Services;

use App\Events\RequestStatusChanged;
use App\Models\Approval;
use App\Models\ProcurementOrder;
use App\Models\ProcurementOrderItem;
use App\Models\Request;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class RequestService
{
    // ── Create ────────────────────────────────────────────────
    public function create(User $requester, array $data): Request
    {
        return DB::transaction(function () use ($requester, $data) {
            $request = Request::create([
                'request_number' => $this->generateNumber(),
                'requester_id'   => $requester->id,
                'department_id'  => $requester->department_id,
                'title'          => $data['title'],
                'description'    => $data['description'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'priority'       => $data['priority'] ?? 2,
                'required_date'  => $data['required_date'] ?? null,
                'status'         => Request::STATUS_DRAFT,
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
    public function submit(Request $request, User $actor, int $version): Request
    {
        $this->assertVersion($request, $version);
        $this->assertTransition($request, Request::STATUS_SUBMITTED);

        return DB::transaction(function () use ($request, $actor) {
            $this->setCurrentUser($actor);

            // Create the verification slot for Purchasing
            Approval::create([
                'request_id'  => $request->id,
                'approver_id' => $this->findPurchasingUser()->id,
                'step'        => Approval::STEP_VERIFY,
            ]);

            $request->update([
                'status'       => Request::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ]);

            event(new RequestStatusChanged($request, Request::STATUS_DRAFT, Request::STATUS_SUBMITTED, $actor));

            return $request->fresh();
        });
    }

    // ── Verify (Purchasing step) ──────────────────────────────
    public function verify(Request $request, User $actor, array $data): Request
    {
        $this->assertVersion($request, $data['version']);
        $this->assertTransition($request, Request::STATUS_VERIFIED);

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

            $request->update(['status' => Request::STATUS_VERIFIED]);
            event(new RequestStatusChanged($request, Request::STATUS_SUBMITTED, Request::STATUS_VERIFIED, $actor));

            return $request->fresh();
        });
    }

    // ── Approve (Manager step) ────────────────────────────────
    public function approve(Request $request, User $actor, array $data): Request
    {
        $this->assertVersion($request, $data['version']);
        $this->assertTransition($request, Request::STATUS_APPROVED);

        return DB::transaction(function () use ($request, $actor, $data) {
            $this->setCurrentUser($actor);

            $approval = $this->findOrFailPendingApproval($request, Approval::STEP_APPROVE);
            $approval->update([
                'action'   => Approval::ACTION_APPROVE,
                'notes'    => $data['notes'] ?? null,
                'acted_at' => now(),
            ]);

            $request->update(['status' => Request::STATUS_APPROVED]);
            event(new RequestStatusChanged($request, Request::STATUS_VERIFIED, Request::STATUS_APPROVED, $actor));

            return $request->fresh();
        });
    }

    // ── Reject ────────────────────────────────────────────────
    public function reject(Request $request, User $actor, array $data): Request
    {
        $this->assertVersion($request, $data['version']);

        // Reject allowed from SUBMITTED or VERIFIED
        if (!in_array($request->status, [Request::STATUS_SUBMITTED, Request::STATUS_VERIFIED])) {
            throw new UnprocessableEntityHttpException('Request cannot be rejected from status: '.$request->status);
        }

        $step = $request->status === Request::STATUS_SUBMITTED
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

            $request->update(['status' => Request::STATUS_REJECTED]);
            event(new RequestStatusChanged($request, $request->status, Request::STATUS_REJECTED, $actor, $data['reason']));

            return $request->fresh();
        });
    }

    // ── Procure ───────────────────────────────────────────────
    public function procure(Request $request, User $actor, array $data): ProcurementOrder
    {
        $this->assertVersion($request, $data['version']);
        $this->assertTransition($request, Request::STATUS_IN_PROCUREMENT);

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
            $request->update(['status' => Request::STATUS_IN_PROCUREMENT]);
            event(new RequestStatusChanged($request, Request::STATUS_APPROVED, Request::STATUS_IN_PROCUREMENT, $actor));

            return $po->load('items', 'vendor', 'request');
        });
    }

    // ── Check stock availability (called after APPROVED) ──────
    public function checkAndMarkStockAvailability(Request $request, User $actor): bool
    {
        $allAvailable = true;

        foreach ($request->items as $item) {
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

                $request->update(['status' => Request::STATUS_READY]);
                event(new RequestStatusChanged($request, Request::STATUS_APPROVED, Request::STATUS_READY, $actor));
            });
        }

        return $allAvailable;
    }

    // ── Helpers ───────────────────────────────────────────────
    private function assertVersion(Request $request, int $version): void
    {
        if ($request->version !== $version) {
            throw new ConflictHttpException(
                'Data has been modified by another user. Please refresh and try again.'
            );
        }
    }

    private function assertTransition(Request $request, string $to): void
    {
        if (!$request->canTransitionTo($to)) {
            throw new UnprocessableEntityHttpException(
                "Cannot transition from '{$request->status}' to '{$to}'."
            );
        }
    }

    private function findOrFailPendingApproval(Request $request, int $step): Approval
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
        $count = Request::withTrashed()->whereYear('created_at', $year)->count() + 1;
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
}
