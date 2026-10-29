<?php

namespace App\Services\Backup;

/** Legacy encrypted-archive compatibility only. The daily ZIP runner does not use this class. */
class Cipher
{
    public const MAGIC = 'SPMSBK01';

    public const CHUNK = 1048576;

    public static function publicKey(string $encoded): string
    {
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new Failure('BACKUP_PUBLIC_KEY must be a base64 Sodium public key.');
        }

        return $key;
    }

    public static function encrypt(string $input, string $output, string $publicKey, ?callable $tick = null): void
    {
        $key = random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $sealed = sodium_crypto_box_seal($key, $publicKey);
        sodium_memzero($key);
        $in = fopen($input, 'rb');
        $out = fopen($output, 'xb');
        if (! $in || ! $out) {
            throw new Failure('Backup encryption files could not be opened.');
        }
        chmod($output, 0600);
        try {
            self::write($out, self::MAGIC.$sealed.$header);
            while (! feof($in)) {
                $plain = fread($in, self::CHUNK);
                if ($plain === false) {
                    throw new Failure('Backup archive could not be read.');
                }
                $encrypted = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain);
                self::write($out, pack('N', strlen($encrypted)).$encrypted);
                $tick && $tick();
            }
            $final = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            self::write($out, pack('N', strlen($final)).$final);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public static function decrypt(string $input, string $output, string $privateKey): void
    {
        if (strlen($privateKey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            throw new Failure('Invalid recovery private key.');
        }
        $in = fopen($input, 'rb');
        $out = fopen($output, 'xb');
        if (! $in || ! $out) {
            throw new Failure('Recovery files could not be opened (output must not exist).');
        }
        chmod($output, 0600);
        try {
            if (self::read($in, 8) !== self::MAGIC) {
                throw new Failure('Unknown backup format.');
            }
            $pair = sodium_crypto_box_keypair_from_secretkey_and_publickey($privateKey, sodium_crypto_box_publickey_from_secretkey($privateKey));
            $key = sodium_crypto_box_seal_open(self::read($in, 80), $pair);
            if ($key === false) {
                throw new Failure('The recovery key does not match this backup.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(self::read($in, 24), $key);
            sodium_memzero($key);
            $final = false;
            while (! $final) {
                $length = unpack('N', self::read($in, 4))[1];
                if ($length < 17 || $length > self::CHUNK + 17) {
                    throw new Failure('Invalid encrypted frame.');
                }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, self::read($in, $length));
                if ($result === false) {
                    throw new Failure('Backup authentication failed.');
                }
                [$plain, $tag] = $result;
                self::write($out, $plain);
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (fread($in, 1) !== '') {
                throw new Failure('Unexpected trailing backup data.');
            }
        } catch (\Throwable $e) {
            fclose($out);
            $out = null;
            unlink($output);
            throw $e;
        } finally {
            fclose($in);
            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    private static function read($stream, int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $part = fread($stream, $bytes - strlen($data));
            if ($part === false || $part === '') {
                throw new Failure('Truncated backup.');
            }
            $data .= $part;
        }

        return $data;
    }

    private static function write($stream, string $data): void
    {
        while ($data !== '') {
            $written = fwrite($stream, $data);
            if (! $written) {
                throw new Failure('Backup write failed; check quota.');
            }
            $data = substr($data, $written);
        }
    }
}
