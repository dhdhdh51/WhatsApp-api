<?php
declare(strict_types=1);

namespace App;

/** In-app 1:1 WebRTC calls: signaling over the DB (polling). Self-hosted STUN/TURN (coturn). */
final class Calls
{
    /** ICE servers for the browser RTCPeerConnection (from settings; falls back to Google STUN). */
    public static function iceServers(): array
    {
        $j = Settings::get('webrtc_ice');
        $arr = $j !== '' ? json_decode($j, true) : null;
        return is_array($arr) && $arr ? $arr : [['urls' => 'stun:stun.l.google.com:19302']];
    }

    public static function invite(string $fromType, int $fromId, string $fromName, string $toType, int $toId, string $mode): array
    {
        $mode = $mode === 'voice' ? 'voice' : 'video';
        $room = 'call-' . bin2hex(random_bytes(6));
        DB::exec("UPDATE calls SET status = 'ended', updated_at = NOW() WHERE from_type = ? AND from_id = ? AND status IN ('ringing','accepted')", [$fromType, $fromId]);
        $id = DB::insert(
            'INSERT INTO calls (from_type, from_id, from_name, to_type, to_id, mode, status, room, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, \'ringing\', ?, NOW(), NOW())',
            [$fromType, $fromId, mb_substr($fromName, 0, 120), $toType, $toId, $mode, $room]
        );
        return ['call_id' => $id, 'room' => $room, 'mode' => $mode];
    }

    /** A ringing call addressed to this actor in the last 60s, or null. */
    public static function incomingFor(string $type, int $id): ?array
    {
        $row = DB::one("SELECT id, from_name, mode FROM calls WHERE to_type = ? AND to_id = ? AND status = 'ringing' AND created_at >= (NOW() - INTERVAL 60 SECOND) ORDER BY id DESC LIMIT 1", [$type, $id]);
        return $row ? ['call_id' => (int) $row['id'], 'from_name' => $row['from_name'], 'mode' => $row['mode']] : null;
    }

    public static function get(int $callId): ?array
    {
        return DB::one('SELECT * FROM calls WHERE id = ?', [$callId]);
    }

    public static function setStatus(int $callId, string $status): void
    {
        DB::exec('UPDATE calls SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $callId]);
    }

    public static function addSignal(int $callId, string $sender, string $data): void
    {
        if (mb_strlen($data) > 100000) {
            return;
        }
        DB::insert('INSERT INTO call_signals (call_id, sender, data, created_at) VALUES (?, ?, ?, NOW())', [$callId, $sender, $data]);
    }

    /** Signals for a call with id > $after that were NOT sent by $mySender. */
    public static function signalsSince(int $callId, int $after, string $mySender): array
    {
        return DB::all('SELECT id, data FROM call_signals WHERE call_id = ? AND id > ? AND sender <> ? ORDER BY id', [$callId, $after, $mySender]);
    }

    /** Is this actor a participant? returns 'caller'|'callee'|null. */
    public static function role(array $call, string $type, int $id): ?string
    {
        if ($call['from_type'] === $type && (int) $call['from_id'] === $id) {
            return 'caller';
        }
        if ($call['to_type'] === $type && (int) $call['to_id'] === $id) {
            return 'callee';
        }
        return null;
    }
}
