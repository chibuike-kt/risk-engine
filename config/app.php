<?php
return [
  'db' => [
    'driver' => 'sqlite',
    'path' => __DIR__ . '/../storage/app.sqlite',
  ],
  'risk' => [
    // hard limits
    'single_tx_max_minor' => 2_000_00,  // 2,000.00 in minor units
    'daily_total_max_minor' => 5_000_00, // 5,000.00
    'velocity' => [
      'window_seconds' => 900, // 15 min
      'max_count' => 6
    ],
    // scoring knobs
    'score' => [
      'base' => 0,
      'amount_over_limit' => 70,
      'velocity_breach' => 50,
      'sanctions_hit' => 100,
      'anomaly_high' => 40,
      'device_mismatch' => 25,
      'geo_mismatch' => 25
    ],
    // tier thresholds
    'tiers' => [
      'LOW' => 0,
      'MEDIUM' => 40,
      'HIGH' => 70
    ]
  ]
];
