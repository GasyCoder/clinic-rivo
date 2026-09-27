<?php

namespace App\Services\Webmail\KeepAlive;

/**
 * Ce qui passe entre une requête de la messagerie et le processus qui garde sa
 * connexion ouverte (MailboxWorker) : une trame = sa longueur sur 4 octets, puis un
 * tableau sérialisé. Aucun objet n'est jamais recréé à la lecture (`allowed_classes`
 * à false) : seulement des textes, des nombres et des tableaux.
 *
 * La prise est locale (socket Unix, dossier réservé au compte du serveur web) : rien
 * ne quitte la machine.
 */
final class WorkerProtocol
{
    /** Une trame plus grande est refusée : une pièce jointe de 10 Mo tient largement. */
    public const MAX_FRAME_BYTES = 64 * 1024 * 1024;

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $payload
     */
    public static function write($stream, array $payload): bool
    {
        $data = serialize($payload);
        $frame = pack('N', strlen($data)).$data;
        $length = strlen($frame);

        for ($written = 0; $written < $length; $written += $count) {
            $count = @fwrite($stream, substr($frame, $written));

            if ($count === false || $count === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * La trame suivante, ou `null` : prise fermée, délai dépassé, trame illisible.
     *
     * @param  resource  $stream
     * @return array<string, mixed>|null
     */
    public static function read($stream): ?array
    {
        $header = self::exactly($stream, 4);

        if ($header === null) {
            return null;
        }

        $length = (int) unpack('N', $header)[1];

        if ($length > self::MAX_FRAME_BYTES) {
            return null;
        }

        $data = $length === 0 ? '' : self::exactly($stream, $length);

        if ($data === null) {
            return null;
        }

        $payload = @unserialize($data, ['allowed_classes' => false]);

        return is_array($payload) ? $payload : null;
    }

    /** @param resource $stream */
    private static function exactly($stream, int $length): ?string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = @fread($stream, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                if (! is_resource($stream) || feof($stream) || (stream_get_meta_data($stream)['timed_out'] ?? false)) {
                    return null;
                }

                continue;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
