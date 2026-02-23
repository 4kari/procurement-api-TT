<?php

namespace App\Policies;

use App\Models\Request as ProcurementRequest;
use App\Models\User;

class RequestPolicy
{
    /**
     * ADMIN bypass semua policy — tidak perlu cek satu per satu
     */
    public function before(User $user, string $ability): bool|null
    {
        \Log::info('Policy before()', [
            'user_id' => $user->id,
            'role'    => $user->role,
            'ability' => $ability,
        ]);
        if ($user->isAdmin()) {
            return true;
        }
        return null; // lanjut ke method policy masing-masing
    }

    /**
     * Semua role bisa lihat list
     * Filter "hanya milik sendiri" untuk Employee ditangani di scope forUser()
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Employee: hanya request miliknya sendiri
     * Role lain: semua request
     */
    public function view(User $user, ProcurementRequest $request): bool
    {
        if ($user->isEmployee()) {
            return $request->requester_id === $user->id;
        }
        return true;
    }

    /**
     * Hanya EMPLOYEE yang bisa buat request
     */
    public function create(User $user): bool
    {
        return $user->isEmployee();
    }

    /**
     * Hanya pemohon (requester) yang bisa submit request miliknya sendiri
     * dan hanya dari status DRAFT
     */
    public function submit(User $user, ProcurementRequest $request): bool
    {
        return $user->isEmployee()
            && $request->requester_id === $user->id
            && $request->status === ProcurementRequest::STATUS_DRAFT;
    }

    /**
     * Hanya PURCHASING yang bisa verifikasi
     * dan hanya dari status SUBMITTED
     */
    public function verify(User $user, ProcurementRequest $request): bool
    {
        return $user->isPurchasing()
            && $request->status === ProcurementRequest::STATUS_SUBMITTED;
    }

    /**
     * Hanya PURCHASING_MANAGER yang bisa approve
     * dan hanya dari status VERIFIED
     */
    public function approve(User $user, ProcurementRequest $request): bool
    {
        \Log::info('Policy approve() check', [
            'user_role'      => $user->role,
            'request_status' => $request->status,
            'is_manager'     => $user->isPurchasingManager(),
            'status_match'   => $request->status === ProcurementRequest::STATUS_VERIFIED,
        ]);
        return $user->isPurchasingManager()
            && $request->status === ProcurementRequest::STATUS_VERIFIED;
    }

    /**
     * PURCHASING bisa reject dari SUBMITTED
     * PURCHASING_MANAGER bisa reject dari VERIFIED
     */
    public function reject(User $user, ProcurementRequest $request): bool
    {
        return ($user->isPurchasing()        && $request->status === ProcurementRequest::STATUS_SUBMITTED)
            || ($user->isPurchasingManager() && $request->status === ProcurementRequest::STATUS_VERIFIED);
    }

    /**
     * Hanya PURCHASING yang bisa buat PO
     * dan hanya dari status APPROVED atau IN_PROCUREMENT
     */
    public function procure(User $user, ProcurementRequest $request): bool
    {
        return $user->isPurchasing()
            && in_array($request->status, [
                ProcurementRequest::STATUS_APPROVED,
                ProcurementRequest::STATUS_IN_PROCUREMENT,
            ], true);
    }

    /**
     * Hanya WAREHOUSE yang bisa mark barang diterima dari vendor
     * dan hanya dari status IN_PROCUREMENT
     */
    public function receive(User $user, ProcurementRequest $request): bool
    {
        return $user->isWarehouse()
            && $request->status === ProcurementRequest::STATUS_IN_PROCUREMENT;
    }

    /**
     * Hanya WAREHOUSE yang bisa mark request selesai (barang diserahkan ke pemohon)
     * dan hanya dari status READY
     */
    public function complete(User $user, ProcurementRequest $request): bool
    {
        \Log::info('Policy complete() check', [
            'user_role'      => $user->role,
            'request_status' => $request->status,
            'is_warehouse'   => $user->isWarehouse(),
            'status_match'   => $request->status === ProcurementRequest::STATUS_READY,
        ]);
        return $user->isWarehouse()
        && $request->status === ProcurementRequest::STATUS_READY;
    }

    /**
     * Hanya pemohon yang bisa batalkan request miliknya sendiri
     * dan hanya dari status DRAFT atau SUBMITTED
     */
    public function cancel(User $user, ProcurementRequest $request): bool
    {
        return $user->isEmployee()
            && $request->requester_id === $user->id
            && in_array($request->status, [
                ProcurementRequest::STATUS_DRAFT,
                ProcurementRequest::STATUS_SUBMITTED,
            ], true);
    }
}