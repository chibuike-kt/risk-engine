<?php
namespace App\Logging;

final class CheckpointSigner {
  private string $privateKeyPem;
  private string $publicKeyPem;

  public function __construct(string $privateKeyPath, string $publicKeyPath) {
    if (!is_file($privateKeyPath) || !is_file($publicKeyPath)) {
      throw new \RuntimeException("signing_keys_missing");
    }
    $this->privateKeyPem = file_get_contents($privateKeyPath) ?: '';
    $this->publicKeyPem = file_get_contents($publicKeyPath) ?: '';
    if ($this->privateKeyPem === '' || $this->publicKeyPem === '') {
      throw new \RuntimeException("signing_keys_unreadable");
    }
  }

  public function publicKeyPem(): string {
    return $this->publicKeyPem;
  }

  public function signCheckpoint(array $checkpoint): string {
    $msg = $this->canonicalCheckpoint($checkpoint);

    $priv = openssl_pkey_get_private($this->privateKeyPem);
    if ($priv === false) throw new \RuntimeException("private_key_invalid");

    $sig = '';
    $ok = openssl_sign($msg, $sig, $priv, OPENSSL_ALGO_ED25519);
    openssl_free_key($priv);

    if (!$ok) throw new \RuntimeException("sign_failed");
    return base64_encode($sig);
  }

  public function verifyCheckpoint(array $checkpoint, string $signatureB64, ?string $publicKeyPem = null): bool {
    $msg = $this->canonicalCheckpoint($checkpoint);

    $pubPem = $publicKeyPem ?? $this->publicKeyPem;
    $pub = openssl_pkey_get_public($pubPem);
    if ($pub === false) return false;

    $sig = base64_decode($signatureB64, true);
    if ($sig === false) return false;

    $ok = openssl_verify($msg, $sig, $pub, OPENSSL_ALGO_ED25519);
    openssl_free_key($pub);

    return $ok === 1;
  }

  private function canonicalCheckpoint(array $c): string {
    // Only sign these fields, stable order.
    $data = [
      'day' => (string)($c['day'] ?? ''),
      'tip_hash' => (string)($c['tip_hash'] ?? ''),
      'audit_count' => (int)($c['audit_count'] ?? 0),
    ];
    return json_encode($data, JSON_UNESCAPED_SLASHES);
  }
}
