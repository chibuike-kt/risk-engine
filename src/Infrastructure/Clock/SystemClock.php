<?php
namespace Infrastructure\Clock;

final class SystemClock {
  public function nowIso(): string { return gmdate('c'); }
}
