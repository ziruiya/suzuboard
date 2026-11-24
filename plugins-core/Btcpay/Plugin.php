<?php

namespace Plugin\Btcpay;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Utils\MoneyAmount;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['BTCPay'] = [
                    'name' => $this->getConfig('display_name', 'BTCPay'),
                    'icon' => $this->getConfig('icon', '₿'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin'
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'btcpay_url' => [
                'label' => 'API接口所在网址',
                'type' => 'string',
                'required' => true,
                'description' => '包含最后的斜杠，例如：https://your-btcpay.com/'
            ],
            'btcpay_storeId' => [
                'label' => 'Store ID',
                'type' => 'string',
                'required' => true,
                'description' => 'BTCPay商店标识符'
            ],
            'btcpay_api_key' => [
                'label' => 'API KEY',
                'type' => 'string',
                'required' => true,
                'description' => '个人设置中的API KEY(非商店设置中的)'
            ],
            'btcpay_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'type' => 'string',
                'required' => true,
                'description' => 'Webhook通知密钥'
            ],
        ];
    }

    public function pay($order): array
    {
        $params = [
            'jsonResponse' => true,
            'amount' => sprintf('%.2f', $order['total_amount'] / 100),
            'currency' => 'CNY',
            'metadata' => [
                'orderId' => $order['trade_no']
            ]
        ];

        $params_string = @json_encode($params);
        $ret_raw = $this->curlPost($this->getConfig('btcpay_url') . 'api/v1/stores/' . $this->getConfig('btcpay_storeId') . '/invoices', $params_string);
        $ret = @json_decode($ret_raw, true);

        if (empty($ret['checkoutLink'])) {
            throw new ApiException("error!");
        }
        
        return [
            'type' => 1,
            'data' => $ret['checkoutLink'],
        ];
    }

    public function notify($params): array|bool
    {
        $payload = trim(request()->getContent());
        $headers = getallheaders();
        $headerName = 'Btcpay-Sig';
        $signraturHeader = isset($headers[$headerName]) ? $headers[$headerName] : '';
        $json_param = json_decode($payload, true);

        $computedSignature = "sha256=" . \hash_hmac('sha256', $payload, $this->getConfig('btcpay_webhook_key'));

        if (!$this->hashEqual($signraturHeader, $computedSignature)) {
            throw new ApiException('HMAC signature does not match', 400);
        }

        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'header' => "Authorization:" . "token " . $this->getConfig('btcpay_api_key') . "\r\n"
            )
        ));

        $invoiceDetailRaw = file_get_contents($this->getConfig('btcpay_url') . 'api/v1/stores/' . $this->getConfig('btcpay_storeId') . '/invoices/' . $json_param['invoiceId'], false, $context);
        $invoiceDetail = $invoiceDetailRaw === false ? null : json_decode($invoiceDetailRaw, true);
        if (!is_array($invoiceDetail) || empty($invoiceDetail['metadata']['orderId'])) {
            throw new ApiException('invoice query failed', 400);
        }

        // SB-AUD-004: the webhook event alone does not prove settlement;
        // the fetched invoice must be in its final settled state
        // (Greenfield "Settled"; legacy "Complete" accepted).
        if (!in_array($invoiceDetail['status'] ?? null, ['Settled', 'Complete'], true)) {
            throw new ApiException('invoice is not settled', 400);
        }

        // SB-AUD-002/030: invoices are created in CNY (see pay()); report
        // the invoiced amount for reconciliation against the order.
        $paidAmountCents = MoneyAmount::majorToCents($invoiceDetail['amount'] ?? null);
        if ($paidAmountCents === null
            || strtoupper((string) ($invoiceDetail['currency'] ?? '')) !== 'CNY') {
            throw new ApiException('invoice amount is not reconcilable', 400);
        }

        $out_trade_no = $invoiceDetail['metadata']["orderId"];
        $pay_trade_no = $json_param['invoiceId'];

        return [
            'trade_no' => $out_trade_no,
            'callback_no' => $pay_trade_no,
            'paid_amount_cents' => $paidAmountCents,
            'paid_currency' => 'CNY'
        ];
    }

    private function curlPost($url, $params = false)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array('Authorization:' . 'token ' . $this->getConfig('btcpay_api_key'), 'Content-Type: application/json')
        );
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private function hashEqual($str1, $str2)
    {
        if (function_exists('hash_equals')) {
            return \hash_equals($str1, $str2);
        }

        if (strlen($str1) != strlen($str2)) {
            return false;
        } else {
            $res = $str1 ^ $str2;
            $ret = 0;

            for ($i = strlen($res) - 1; $i >= 0; $i--) {
                $ret |= ord($res[$i]);
            }
            return !$ret;
        }
    }
} 