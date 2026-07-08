<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Journal d'audit (EF-10.3, §8) : écriture dans audit_logs, table en
 * insertion seule (règles PostgreSQL). Une entrée par action sensible —
 * recherches, imports, exports (EF-07.5), connexions.
 */
class Audit
{
    /** @param array<string, mixed>|null $payload */
    public static function log(
        string $action,
        ?Model $subject = null,
        ?array $payload = null,
        ?int $userId = null,
    ): void {
        DB::table('audit_logs')->insert([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'ip' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'payload' => $payload !== null ? json_encode($payload) : null,
            'created_at' => now(),
        ]);
    }
}
