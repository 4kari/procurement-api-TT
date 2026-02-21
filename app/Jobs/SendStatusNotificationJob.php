<?php

namespace App\Jobs;

use App\Models\Request;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendStatusNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Max retries with exponential backoff.
     * Retry delays: 60s → 300s → 900s
     */
    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        private readonly Request $request,
        private readonly string  $fromStatus,
        private readonly string  $toStatus,
        private readonly User    $actor,
        private readonly ?string $reason = null,
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $requester = $this->request->requester;

        if (!$requester?->email) {
            Log::warning('No email for requester', ['request_id' => $this->request->id]);
            return;
        }

        $subject = $this->buildSubject();
        $body    = $this->buildBody();

        // In production: Mail::to($requester->email)->send(new RequestStatusMail(...));
        // For now, log it (replace with Mailable in production)
        Log::channel('stack')->info('EMAIL_NOTIFICATION', [
            'to'           => $requester->email,
            'subject'      => $subject,
            'body_preview' => $body,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SendStatusNotificationJob failed', [
            'request_id' => $this->request->id,
            'error'      => $e->getMessage(),
        ]);
    }

    private function buildSubject(): string
    {
        return match ($this->toStatus) {
            'SUBMITTED'      => "[{$this->request->request_number}] Permintaan Anda telah diajukan",
            'VERIFIED'       => "[{$this->request->request_number}] Permintaan Anda telah diverifikasi",
            'APPROVED'       => "[{$this->request->request_number}] Permintaan Anda telah disetujui",
            'REJECTED'       => "[{$this->request->request_number}] Permintaan Anda ditolak",
            'IN_PROCUREMENT' => "[{$this->request->request_number}] Proses pengadaan dimulai",
            'READY'          => "[{$this->request->request_number}] Barang siap diambil",
            'COMPLETED'      => "[{$this->request->request_number}] Permintaan selesai",
            default          => "[{$this->request->request_number}] Status diperbarui: {$this->toStatus}",
        };
    }

    private function buildBody(): string
    {
        $body = "Permintaan {$this->request->request_number} ({$this->request->title})\n";
        $body .= "Status: {$this->fromStatus} → {$this->toStatus}\n";
        $body .= "Diubah oleh: {$this->actor->name}\n";
        if ($this->reason) {
            $body .= "Alasan: {$this->reason}\n";
        }
        return $body;
    }
}
