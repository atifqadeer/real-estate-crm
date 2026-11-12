<?php
namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogger
{
    /** Keys that must never reach the audit log. */
    private array $redact = ['password', 'token', 'access_token', 'refresh_token', 'secret'];

    public function log(?int $organizationId, ?int $userId, string $action, array $metadata = [], ?Request $request = null, ?string $auditableType = null, ?int $auditableId = null): AuditLog
    {
        return AuditLog::create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'metadata' => $this->clean($metadata),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    private function clean(array $metadata): array
    {
        foreach ($metadata as $k => $v) {
            if (in_array(strtolower((string) $k), $this->redact, true)) {
                $metadata[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $metadata[$k] = $this->clean($v);
            }
        }
        return $metadata;
    }
}
