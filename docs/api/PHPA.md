# PHPA API

Source: `library/PHPA.php`  
SHA-256: `cc71cbbaf3e4c4858c75b3fbf5ae7a67358087ab8fe262924d4011398a64c202`

Classes: `PHPAGatewayInterface`, `PHPA_BaseGateway`, `PHPACourierInterface`, `PHPA_Courier`, `PHPA`, `PHPA_Stripe`, `PHPA_Paypal`, `PHPA_Razorpay`, `PHPA_Braintree`, `PHPA_Authorize`, `PHPA_Twocheckout`, `PHPA_Payoneer`, `PHPA_Square`, `PHPA_Adyen`, `PHPA_Mollie`, `PHPA_Coinbase`, `PHPA_Binance`, `PHPA_Coinpayments`, `PHPA_Bitpay`, `PHPA_Nowpayments`, `PHPA_Cryptocom`, `PHPA_Coingate`, `PHPA_Trustwallet`, `PHPA_Btcpay`, `PHPA_Metamask`, `PHPA_Bkash`, `PHPA_Nagad`, `PHPA_Rocket`, `PHPA_Sslcommerz`, `PHPA_Aamarpay`, `PHPA_Surjopay`, `PHPA_Portwallet`, `PHPA_Upay`, `PHPA_Shurjomukhi`, `PHPA_Nexuspay`

## Public methods

### `setKeys(string $key1, string $key2 = '', string $key3 = '', string $key4 = ''): self`

See executable source and call sites for the behavioral contract.

### `setLogic(?callable $chargeCallback = null, ?callable $verifyCallback = null): self`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `setLogic(?callable $chargeCallback = null, ?callable $verifyCallback = null): self`

See executable source and call sites for the behavioral contract.

### `setRefundLogic(?callable $callback): self`

See executable source and call sites for the behavioral contract.

### `setWebhookLogic(?callable $callback): self`

See executable source and call sites for the behavioral contract.

### `setTransport(?callable $callback): self`

Injectable transport for deterministic tests and private gateway adapters.

### `timeout(int $seconds, ?int $connectSeconds = null): self`

See executable source and call sites for the behavioral contract.

### `expect(string $orderId, ?float $amount = null, ?string $currency = null): self`

See executable source and call sites for the behavioral contract.

### `setKeys(string $key1, string $key2 = '', string $key3 = '', string $key4 = ''): self`

See executable source and call sites for the behavioral contract.

### `sandbox(bool $status = true): self`

See executable source and call sites for the behavioral contract.

### `capabilities(): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `webhook(string $payload, array $headers = [], array $context = []): array`

See executable source and call sites for the behavioral contract.

### `setKeys(string $key1, string $key2 = '', string $key3 = '', string $key4 = ''): self`

See executable source and call sites for the behavioral contract.

### `configure(array $options): self`

See executable source and call sites for the behavioral contract.

### `sandbox(bool $status = true): self`

See executable source and call sites for the behavioral contract.

### `setTransport(?callable $callback): self`

See executable source and call sites for the behavioral contract.

### `create(array $shipment): array`

See executable source and call sites for the behavioral contract.

### `track(string $trackingId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `rate(array $shipment): array`

See executable source and call sites for the behavioral contract.

### `cancel(string $trackingId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `label(string $trackingId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `pickup(array $pickup): array`

See executable source and call sites for the behavioral contract.

### `call(string $operation, array $payload = [], array $options = []): array`

See executable source and call sites for the behavioral contract.

### `capabilities(): array`

See executable source and call sites for the behavioral contract.

### `__construct(array $profile)`

See executable source and call sites for the behavioral contract.

### `setKeys(string $key1, string $key2 = '', string $key3 = '', string $key4 = ''): self`

See executable source and call sites for the behavioral contract.

### `configure(array $options): self`

See executable source and call sites for the behavioral contract.

### `sandbox(bool $status = true): self`

See executable source and call sites for the behavioral contract.

### `setTransport(?callable $callback): self`

See executable source and call sites for the behavioral contract.

### `profile(): array`

See executable source and call sites for the behavioral contract.

### `name(): string`

See executable source and call sites for the behavioral contract.

### `capabilities(): array`

See executable source and call sites for the behavioral contract.

### `create(array $shipment): array`

See executable source and call sites for the behavioral contract.

### `track(string $trackingId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `rate(array $shipment): array`

See executable source and call sites for the behavioral contract.

### `cancel(string $trackingId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `label(string $trackingId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `pickup(array $pickup): array`

See executable source and call sites for the behavioral contract.

### `call(string $operation, array $payload = [], array $options = []): array`

See executable source and call sites for the behavioral contract.

### `static courier(string $name): PHPACourierInterface`

See executable source and call sites for the behavioral contract.

### `static extendCourier(string $name, callable $factory): void`

See executable source and call sites for the behavioral contract.

### `static courierAvailable(?string $region = null): array`

See executable source and call sites for the behavioral contract.

### `static courierProfile(string $name): array`

See executable source and call sites for the behavioral contract.

### `static extend(string $name, string $className): void`

See executable source and call sites for the behavioral contract.

### `static available(): array`

See executable source and call sites for the behavioral contract.

### `static gatewayCapabilities(string $name): array`

See executable source and call sites for the behavioral contract.

### `static __callStatic($name, $arguments): PHPAGatewayInterface`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `webhook(string $payload, array $headers = [], array $context = []): array`

See executable source and call sites for the behavioral contract.

### `capabilities(): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `capture(string $orderId): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `verifySignature(string $orderId, string $paymentId, string $signature): bool`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `webhook(string $payload, array $headers = [], array $context = []): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `webhook(string $payload, array $headers = [], array $context = []): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `webhook(string $payload, array $headers = [], array $context = []): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `webhook(string $payload, array $headers = [], array $context = []): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `webhook(string $payload, array $headers = [], array $context = []): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `webhook(string $payload, array $headers = [], array $context = []): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $paymentId): array`

See executable source and call sites for the behavioral contract.

### `execute(string $paymentId): array`

See executable source and call sites for the behavioral contract.

### `refundPayment(string $paymentId, string $trxId, float $amount, string $sku = 'refund', string $reason = 'Customer refund'): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $paymentRefId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `webhook(string $payload, array $headers = [], array $context = []): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.

### `charge(float $amount, string $currency, string $orderId, array $options = []): array`

See executable source and call sites for the behavioral contract.

### `verify(string $transactionId): array`

See executable source and call sites for the behavioral contract.

### `refund(string $transactionId, ?float $amount = null): array`

See executable source and call sites for the behavioral contract.


Generated by `mystack docs:build`; do not hand-edit.
