<?php
declare(strict_types=1);

namespace App;

/** Log of every sent / failed / received message (used for the message log & conversation view). */
final class MessageLog
{
    /**
     * @param array{bc_id?:?int,direction:string,chat_id:string,body:string,kind:string,status:string,
     *              wa_message_id?:?string,reply_to_id?:?string,queue_id?:?int,error?:?string,report_date?:?string} $m
     * @return int inserted id, 0 when the incoming message was already logged (duplicate webhook)
     */
    public static function add(array $m): int
    {
        $sql = 'INSERT IGNORE INTO messages
                (bc_id, direction, chat_id, body, kind, status, wa_message_id, wa_short_id, reply_to_id, queue_id, error, report_date, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
        $stmt = DB::run($sql, [
            $m['bc_id'] ?? null,
            $m['direction'],
            mb_substr($m['chat_id'], 0, 64),
            $m['body'],
            $m['kind'],
            $m['status'],
            $m['wa_message_id'] ?? null,
            WahaClient::shortId($m['wa_message_id'] ?? null),
            $m['reply_to_id'] ?? null,
            $m['queue_id'] ?? null,
            isset($m['error']) ? mb_substr((string) $m['error'], 0, 500) : null,
            $m['report_date'] ?? null,
        ]);
        return $stmt->rowCount() > 0 ? (int) DB::pdo()->lastInsertId() : 0;
    }

    public static function setKind(int $id, string $kind, ?int $bcId = null): void
    {
        DB::exec('UPDATE messages SET kind = ?, bc_id = COALESCE(?, bc_id) WHERE id = ?', [$kind, $bcId, $id]);
    }

    /** Which form/reminder (and report date) a quoted message id refers to. */
    public static function findSentForm(string $quotedId): ?array
    {
        $short = WahaClient::shortId($quotedId);
        return DB::one(
            "SELECT bc_id, report_date, kind FROM messages
             WHERE direction = 'out' AND (wa_message_id = ? OR wa_short_id = ?)
             ORDER BY id DESC LIMIT 1",
            [$quotedId, $short]
        );
    }
}
