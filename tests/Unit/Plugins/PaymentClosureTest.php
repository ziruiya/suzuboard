<?php

/**
 * Namespace-scoped test doubles for the two webhook plugins that read
 * superglobal-ish helpers (getallheaders) or remote URLs
 * (file_get_contents) with unqualified calls inside their own namespace.
 * PHP resolves those calls to the plugin namespace first, so defining
 * them here intercepts the network/header edge without touching the
 * plugins under test.
 */
namespace Plugin\Coinbase {
    function getallheaders(): array
    {
        return $GLOBALS['__coinbase_headers'] ?? [];
    }
}

namespace Plugin\Btcpay {
    function getallheaders(): array
    {
        return $GLOBALS['__btcpay_headers'] ?? [];
    }

    function file_get_contents($filename, $use_include_path = false, $context = null, $offset = 0, $maxlen = null)
    {
        return $GLOBALS['__btcpay_invoice_body'] ?? false;
    }
}

namespace Plugin\CoinPayments {
    function getallheaders(): array
    {
        return $GLOBALS['__coinpayments_headers'] ?? [];
    }
}

namespace Tests\Unit\Plugins {

    use App\Exceptions\ApiException;
    use Illuminate\Http\Request;
    use Tests\TestCase;

    require_once __DIR__ . '/../../../plugins-core/Epay/Plugin.php';
    require_once __DIR__ . '/../../../plugins-core/Mgate/Plugin.php';
    require_once __DIR__ . '/../../../plugins-core/Coinbase/Plugin.php';
    require_once __DIR__ . '/../../../plugins-core/Btcpay/Plugin.php';
    require_once __DIR__ . '/../../../plugins-core/CoinPayments/Plugin.php';

    /**
     * SB-AUD payment-closure batch: AUD-029 (MGate status), AUD-002/030
     * (amount/currency reconciliation data), AUD-004 (Coinbase/BTCPay
     * final-state checks), AUD-031 (MGate TLS verification).
     */
    class PaymentClosureTest extends TestCase
    {
        protected function tearDown(): void
        {
            // The test database is a shared sqlite file (config maps
            // ':memory:' through base_path()); release this test's
            // connection explicitly so no lock lingers into the next
            // test's RefreshDatabase migration.
            \Illuminate\Support\Facades\DB::purge();
            \Illuminate\Support\Facades\DB::disconnect();
            parent::tearDown();
        }

        // ---------- EPay (AUD-002/030, the live payment chain) ----------

        private function epayPlugin(): \Plugin\Epay\Plugin
        {
            $plugin = new \Plugin\Epay\Plugin('EPay');
            $plugin->setConfig(['pid' => '1001', 'key' => 'epay-secret', 'url' => 'https://pay.example.com', 'type' => 'alipay']);
            return $plugin;
        }

        private function epaySignedParams(array $overrides = []): array
        {
            $params = array_merge([
                'pid' => '1001',
                'type' => 'alipay',
                'out_trade_no' => 'ORDER20261125001',
                'trade_no' => 'EPAY-TX-1',
                'name' => 'ORDER20261125001',
                'money' => '29.00',
                'trade_status' => 'TRADE_SUCCESS',
                'sign_type' => 'MD5',
            ], $overrides);
            $signParams = $params;
            unset($signParams['sign_type']);
            ksort($signParams);
            $params['sign'] = md5(stripslashes(urldecode(http_build_query($signParams))) . 'epay-secret');
            return $params;
        }

        public function test_epay_notify_reports_paid_amount_for_reconciliation(): void
        {
            $verify = $this->epayPlugin()->notify($this->epaySignedParams());
            $this->assertIsArray($verify);
            $this->assertSame('ORDER20261125001', $verify['trade_no']);
            $this->assertSame(2900, $verify['paid_amount_cents']);
            $this->assertSame('CNY', $verify['paid_currency']);
        }

        public function test_epay_notify_without_amount_fails_closed(): void
        {
            $params = $this->epaySignedParams();
            unset($params['money']);
            // Re-sign without money so only the amount check can reject it.
            $signParams = $params;
            unset($signParams['sign'], $signParams['sign_type']);
            ksort($signParams);
            $params['sign'] = md5(stripslashes(urldecode(http_build_query($signParams))) . 'epay-secret');
            $this->assertFalse($this->epayPlugin()->notify($params));
        }

        public function test_epay_notify_with_malformed_amount_fails_closed(): void
        {
            $this->assertFalse($this->epayPlugin()->notify($this->epaySignedParams(['money' => '29.005'])));
        }

        public function test_epay_notify_rejects_bad_signature(): void
        {
            $params = $this->epaySignedParams();
            $params['sign'] = str_repeat('0', 32);
            $this->assertFalse($this->epayPlugin()->notify($params));
        }

        // ---------- MGate (AUD-029 status, AUD-002 amount, AUD-031 TLS) ----------

        private function mgatePlugin(): \Plugin\Mgate\Plugin
        {
            $plugin = new \Plugin\Mgate\Plugin('MGate');
            $plugin->setConfig([
                'mgate_url' => 'https://mgate.example.com',
                'mgate_app_id' => 'app-1',
                'mgate_app_secret' => 'mgate-secret',
            ]);
            return $plugin;
        }

        private function mgateSignedParams(array $overrides = []): array
        {
            $params = array_merge([
                'app_id' => 'app-1',
                'out_trade_no' => 'ORDER20261125002',
                'trade_no' => 'MG-TX-1',
                'total_amount' => '2900',
                'status' => 'SUCCESS',
            ], $overrides);
            $signParams = $params;
            ksort($signParams);
            $params['sign'] = md5(http_build_query($signParams) . 'mgate-secret');
            return $params;
        }

        public function test_mgate_notify_accepts_final_status_and_reports_amount(): void
        {
            $verify = $this->mgatePlugin()->notify($this->mgateSignedParams());
            $this->assertIsArray($verify);
            $this->assertSame(2900, $verify['paid_amount_cents']);
            $this->assertSame('CNY', $verify['paid_currency']);
        }

        public function test_mgate_notify_without_status_fails_closed(): void
        {
            $params = $this->mgateSignedParams();
            unset($params['status']);
            $signParams = $params;
            unset($signParams['sign']);
            ksort($signParams);
            $params['sign'] = md5(http_build_query($signParams) . 'mgate-secret');
            $this->assertFalse($this->mgatePlugin()->notify($params));
        }

        public function test_mgate_notify_with_failed_status_fails_closed(): void
        {
            $this->assertFalse($this->mgatePlugin()->notify($this->mgateSignedParams(['status' => 'FAILED'])));
            $this->assertFalse($this->mgatePlugin()->notify($this->mgateSignedParams(['status' => 'PENDING'])));
        }

        public function test_mgate_notify_without_amount_fails_closed(): void
        {
            $params = $this->mgateSignedParams();
            unset($params['total_amount']);
            $signParams = $params;
            unset($signParams['sign']);
            ksort($signParams);
            $params['sign'] = md5(http_build_query($signParams) . 'mgate-secret');
            $this->assertFalse($this->mgatePlugin()->notify($params));
        }

        public function test_mgate_pay_no_longer_disables_tls_verification(): void
        {
            $source = file_get_contents(__DIR__ . '/../../../plugins-core/Mgate/Plugin.php');
            $this->assertStringNotContainsString('CURLOPT_SSL_VERIFYPEER, 0', $source);
            $this->assertStringContainsString('CURLOPT_SSL_VERIFYPEER, true', $source);
            $this->assertStringContainsString('CURLOPT_SSL_VERIFYHOST, 2', $source);
        }

        // ---------- Coinbase (AUD-004 final state, AUD-002 amount) ----------

        private function coinbaseNotify(string $payload): array
        {
            $GLOBALS['__coinbase_headers'] = [
                'X-Cc-Webhook-Signature' => hash_hmac('sha256', $payload, 'cb-webhook-secret'),
            ];
            $this->app->instance('request', Request::create('/notify', 'POST', [], [], [], [], $payload));
            $plugin = new \Plugin\Coinbase\Plugin('Coinbase');
            $plugin->setConfig([
                'coinbase_url' => 'https://api.commerce.coinbase.com/charges',
                'coinbase_api_key' => 'cb-key',
                'coinbase_webhook_key' => 'cb-webhook-secret',
            ]);
            try {
                return $plugin->notify([]);
            } finally {
                $GLOBALS['__coinbase_headers'] = [];
            }
        }

        private function coinbasePayload(string $eventType, string $amount = '29.00', string $currency = 'CNY'): string
        {
            return json_encode([
                'event' => [
                    'id' => 'evt-1',
                    'type' => $eventType,
                    'data' => [
                        'metadata' => ['outTradeNo' => 'ORDER20261125003'],
                        'pricing' => ['local' => ['amount' => $amount, 'currency' => $currency]],
                    ],
                ],
            ]);
        }

        public function test_coinbase_non_final_event_does_not_complete(): void
        {
            foreach (['charge:created', 'charge:pending', 'charge:failed', 'charge:delayed'] as $type) {
                try {
                    $this->coinbaseNotify($this->coinbasePayload($type));
                    $this->fail("event {$type} must not be treated as paid");
                } catch (ApiException $e) {
                    $this->assertStringContainsString('not in a final paid state', $e->getMessage());
                }
            }
        }

        public function test_coinbase_confirmed_event_reports_amount(): void
        {
            $verify = $this->coinbaseNotify($this->coinbasePayload('charge:confirmed'));
            $this->assertSame('ORDER20261125003', $verify['trade_no']);
            $this->assertSame(2900, $verify['paid_amount_cents']);
            $this->assertSame('CNY', $verify['paid_currency']);
        }

        public function test_coinbase_foreign_currency_amount_is_not_reconcilable(): void
        {
            try {
                $this->coinbaseNotify($this->coinbasePayload('charge:resolved', '29.00', 'USD'));
                $this->fail('a USD charge must not reconcile against a CNY order');
            } catch (ApiException $e) {
                $this->assertStringContainsString('not reconcilable', $e->getMessage());
            }
        }

        // ---------- BTCPay (AUD-004 settled state, AUD-002 amount) ----------

        private function btcpayNotify(array $invoice): array
        {
            $payload = json_encode(['invoiceId' => 'inv-1', 'type' => 'InvoiceSettled']);
            $GLOBALS['__btcpay_headers'] = [
                'Btcpay-Sig' => 'sha256=' . hash_hmac('sha256', $payload, 'btcpay-webhook-secret'),
            ];
            $GLOBALS['__btcpay_invoice_body'] = json_encode($invoice);
            $this->app->instance('request', Request::create('/notify', 'POST', [], [], [], [], $payload));
            $plugin = new \Plugin\Btcpay\Plugin('BTCPay');
            $plugin->setConfig([
                'btcpay_url' => 'https://btcpay.example.com/',
                'btcpay_storeId' => 'store-1',
                'btcpay_api_key' => 'btcpay-key',
                'btcpay_webhook_key' => 'btcpay-webhook-secret',
            ]);
            try {
                return $plugin->notify([]);
            } finally {
                $GLOBALS['__btcpay_headers'] = [];
                $GLOBALS['__btcpay_invoice_body'] = false;
            }
        }

        public function test_btcpay_unsettled_invoice_does_not_complete(): void
        {
            foreach (['New', 'Processing', 'Expired', 'Invalid'] as $status) {
                try {
                    $this->btcpayNotify([
                        'status' => $status,
                        'amount' => '29.00',
                        'currency' => 'CNY',
                        'metadata' => ['orderId' => 'ORDER20261125004'],
                    ]);
                    $this->fail("invoice status {$status} must not complete an order");
                } catch (ApiException $e) {
                    $this->assertStringContainsString('not settled', $e->getMessage());
                }
            }
        }

        public function test_btcpay_settled_invoice_reports_amount(): void
        {
            $verify = $this->btcpayNotify([
                'status' => 'Settled',
                'amount' => '29.00',
                'currency' => 'CNY',
                'metadata' => ['orderId' => 'ORDER20261125004'],
            ]);
            $this->assertSame('ORDER20261125004', $verify['trade_no']);
            $this->assertSame(2900, $verify['paid_amount_cents']);
        }

        // ---------- CoinPayments (AUD-002 amount when denominated in CNY) ----------

        private function coinPaymentsNotify(array $params): array|string
        {
            $signParams = $params;
            ksort($signParams);
            $request = stripslashes(http_build_query($signParams));
            $GLOBALS['__coinpayments_headers'] = [
                'Hmac' => hash_hmac('sha512', $request, 'cp-ipn-secret'),
            ];
            $plugin = new \Plugin\CoinPayments\Plugin('CoinPayments');
            $plugin->setConfig([
                'coinpayments_merchant_id' => 'merchant-1',
                'coinpayments_ipn_secret' => 'cp-ipn-secret',
                'coinpayments_currency' => 'CNY',
            ]);
            try {
                return $plugin->notify($params);
            } finally {
                $GLOBALS['__coinpayments_headers'] = [];
            }
        }

        public function test_coinpayments_completed_ipn_reports_cny_amount(): void
        {
            $verify = $this->coinPaymentsNotify([
                'merchant' => 'merchant-1',
                'item_number' => 'ORDER20261125005',
                'txn_id' => 'CP-TX-1',
                'status' => '100',
                'currency1' => 'CNY',
                'amount1' => '29.00',
            ]);
            $this->assertIsArray($verify);
            $this->assertSame(2900, $verify['paid_amount_cents']);
            $this->assertSame('CNY', $verify['paid_currency']);
        }

        public function test_coinpayments_crypto_amount_is_not_misreported(): void
        {
            // SB-MONEY-003 supersedes the AUD-era expectation: a success
            // IPN whose amount cannot be reconciled to the CNY order
            // total must fail closed, not complete the order with the
            // paid fields silently omitted.
            $this->expectException(ApiException::class);
            $this->coinPaymentsNotify([
                'merchant' => 'merchant-1',
                'item_number' => 'ORDER20261125005',
                'txn_id' => 'CP-TX-1',
                'status' => '100',
                'currency1' => 'USDT',
                'amount1' => '4.10',
            ]);
        }

        public function test_coinpayments_success_without_amount_fails_closed(): void
        {
            $this->expectException(ApiException::class);
            $this->coinPaymentsNotify([
                'merchant' => 'merchant-1',
                'item_number' => 'ORDER20261125005',
                'txn_id' => 'CP-TX-1',
                'status' => '100',
                'currency1' => 'CNY',
                // amount1 missing entirely: nothing verifiable to report.
            ]);
        }
    }
}
