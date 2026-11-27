<?php

namespace App\Services\Collab;

/**
 * This installation's cluster identity: an Ed25519 key pair created by the
 * launcher (config.json, passed as OPENQDA_NODE_KEY). It signs every
 * operation, so changes keep their author when they are relayed through
 * other laptops. The node id is derived from the public key exactly like
 * the launcher does (cluster.NodeID).
 */
final class NodeIdentity
{
    private function __construct(
        public readonly string $seed,
        private readonly string $secret,
        public readonly string $pub,
        public readonly string $node,
    ) {}

    public static function fromSeed(string $seedB64): self
    {
        $seed = base64_decode($seedB64, true);
        if ($seed === false || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new \RuntimeException('Invalid node key.');
        }
        $pair = sodium_crypto_sign_seed_keypair($seed);
        $public = sodium_crypto_sign_publickey($pair);

        return new self($seedB64, sodium_crypto_sign_secretkey($pair), base64_encode($public), self::nodeIdOf($public));
    }

    public static function nodeIdOf(string $publicRaw): string
    {
        return 'n'.bin2hex(substr(hash('sha256', $publicRaw, true), 0, 10));
    }

    /** The node id belonging to a base64 public key, or null if malformed. */
    public static function nodeOfPub(string $pubB64): ?string
    {
        $raw = base64_decode($pubB64, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return null;
        }

        return self::nodeIdOf($raw);
    }

    public function sign(string $message): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, $this->secret));
    }

    public static function verify(string $pubB64, string $message, string $sigB64): bool
    {
        $pub = base64_decode($pubB64, true);
        $sig = base64_decode($sigB64, true);
        if ($pub === false || $sig === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, $message, $pub);
    }
}
