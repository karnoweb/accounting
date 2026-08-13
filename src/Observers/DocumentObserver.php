<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Observers;

use Karnoweb\Accounting\Enums\AuditAction;
use Karnoweb\Accounting\Enums\DocumentStatus;
use Karnoweb\Accounting\Events\DocumentPosted;
use Karnoweb\Accounting\Events\DocumentVoided;
use Karnoweb\Accounting\Models\Document;
use Karnoweb\Accounting\Models\DocumentLog;
use Karnoweb\Accounting\Services\BalanceService;

class DocumentObserver
{
    /** @var array<int, DocumentStatus|null> */
    private static array $oldStatusByDocumentId = [];

    public function __construct(
        private BalanceService $balanceService
    ) {}

    public function created(Document $document): void
    {
        $this->log($document, AuditAction::CREATED, null, $document->toArray());
    }

    public function updating(Document $document): void
    {
        $statusRaw = $document->getOriginal('status');
        self::$oldStatusByDocumentId[$document->id] = $statusRaw instanceof DocumentStatus
            ? $statusRaw
            : DocumentStatus::tryFrom((string) $statusRaw);
    }

    public function updated(Document $document): void
    {
        $oldStatus = self::$oldStatusByDocumentId[$document->id] ?? null;
        unset(self::$oldStatusByDocumentId[$document->id]);
        $newStatus = $document->status;

        if ($oldStatus && $oldStatus !== $newStatus) {
            $this->handleStatusChange($document, $oldStatus, $newStatus);
        } else {
            $this->log(
                $document,
                AuditAction::UPDATED,
                $document->_oldValues ?? [],
                $document->toArray()
            );
        }
    }

    protected function handleStatusChange(Document $document, DocumentStatus $oldStatus, DocumentStatus $newStatus): void
    {
        $action = match ($newStatus) {
            DocumentStatus::PENDING => AuditAction::SUBMITTED,
            DocumentStatus::APPROVED => AuditAction::APPROVED,
            DocumentStatus::POSTED => AuditAction::POSTED,
            DocumentStatus::VOIDED => AuditAction::VOIDED,
            DocumentStatus::DRAFT => $oldStatus === DocumentStatus::PENDING ? AuditAction::REJECTED : AuditAction::UPDATED,
            default => AuditAction::UPDATED,
        };

        $this->log(
            $document,
            $action,
            ['status' => $oldStatus->value],
            ['status' => $newStatus->value]
        );

        match ($newStatus) {
            DocumentStatus::POSTED => $this->handlePosted($document),
            DocumentStatus::VOIDED => $this->handleVoided($document),
            default => null,
        };
    }

    protected function handlePosted(Document $document): void
    {
        $this->balanceService->updateAfterDocument($document);
        event(new DocumentPosted($document));
    }

    protected function handleVoided(Document $document): void
    {
        $this->balanceService->reverseDocument($document);
        $reason = $this->extractVoidReason($document);
        event(new DocumentVoided($document, $reason));
    }

    protected function extractVoidReason(Document $document): string
    {
        if ( ! $document->notes) {
            return '';
        }

        if (preg_match('/دلیل ابطال:\s*(.+)$/m', $document->notes, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    protected function log(Document $document, AuditAction $action, ?array $oldValues, ?array $newValues): void
    {
        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => $this->currentUserId(),
            'action' => $action->value,
            'description' => $this->getDescription($action, $document),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $this->requestIp(),
            'user_agent' => $this->requestUserAgent(),
        ]);
    }

    protected function currentUserId(): ?int
    {
        try {
            return auth()->id();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function requestIp(): ?string
    {
        try {
            return request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function requestUserAgent(): ?string
    {
        try {
            return request()->userAgent();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function getDescription(AuditAction $action, Document $document): string
    {
        $number = $document->number;

        return match ($action) {
            AuditAction::CREATED => "سند شماره {$number} ایجاد شد",
            AuditAction::UPDATED => "سند شماره {$number} ویرایش شد",
            AuditAction::SUBMITTED => "سند شماره {$number} برای تأیید ارسال شد",
            AuditAction::APPROVED => "سند شماره {$number} تأیید شد",
            AuditAction::REJECTED => "سند شماره {$number} رد شد",
            AuditAction::POSTED => "سند شماره {$number} ثبت قطعی شد",
            AuditAction::VOIDED => "سند شماره {$number} ابطال شد",
            AuditAction::RESTORED => "سند شماره {$number} بازیابی شد",
        };
    }
}
