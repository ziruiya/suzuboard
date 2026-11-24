<?php

namespace Plugin\Coinbase;

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
                $methods['Coinbase'] = [
                    'name' => $this->getConfig('display_name', 'Coinbase'),
                    'icon' => $this->getConfig('icon', '🪙'),
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
            'coinbase_url' => [
                'label' => '接口地址',
                'type' => 'string',
                'required' => true,
                'description' => 'Coinbase Commerce API地址'
            ],
            'coinbase_api_key' => [
                'label' => 'API KEY',
                'type' => 'string',
                'required' => true,
                'description' => 'Coinbase Commerce API密钥'
            ],
            'coinbase_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'type' => 'string',
                'required' => true,
                'description' => 'Webhook签名验证密钥'
            ],
        ];
    }

    public function pay($order): array
    {
        $params = [
            'name' => '订阅套餐',
            'description' => '订单号 ' . $order['trade_no'],
            'pricing_type' => 'fixed_price',
            'local_price' => [
                'amount' => sprintf('%.2f', $order['total_amount'] / 100),
                'currency' => 'CNY'
            ],
            'metadata' => [
                "outTradeNo" => $order['trade_no'],
            ],
        ];

        $params_string = http_build_query($params);
        $ret_raw = $this->curlPost($this->getConfig('coinbase_url'), $params_string);
        $ret = @json_decode($ret_raw, true);

        if (empty($ret['data']['hosted_url'])) {
            throw new ApiException("error!");
        }
        
        return [
            'type' => 1,
            'data' => $ret['data']['hosted_url'],
        ];
    }

    public function notify($params): array
    {
        $payload = trim(request()->getContent());
        $json_param = json_decode($payload, true);

        $headerName = 'X-Cc-Webhook-Signature';
        $headers = getallheaders();
        $signatureHeader = isset($headers[$headerName]) ? $headers[$headerName] : '';
        $computedSignature = \hash_hmac('sha256', $payload, $this->getConfig('coinbase_webhook_key'));

        if (!$this->hashEqual($signatureHeader, $computedSignature)) {
            throw new ApiException('HMAC signature does not match', 400);
        }

        // SB-AUD-004: a signed webhook proves origin, not settlement.
        // Only a final charge event may complete an order; created /
        // pending / delayed / failed events fail closed (Coinbase keeps
        // retrying, and the final event completes the order later).
        $eventType = $json_param['event']['type'] ?? null;
        if (!in_array($eventType, ['charge:confirmed', 'charge:resolved'], true)) {
            throw new ApiException('charge is not in a final paid state', 400);
        }

        $out_trade_no = $json_param['event']['data']['metadata']['outTradeNo'] ?? null;
        $pay_trade_no = $json_param['event']['id'] ?? null;
        if (!is_string($out_trade_no) || $out_trade_no === '' || !is_string($pay_trade_no) || $pay_trade_no === '') {
            throw new ApiException('charge payload is incomplete', 400);
        }

        // SB-AUD-002/030: charges are created in CNY (see pay()); report
        // the charged local amount for reconciliation against the order.
        $local = $json_param['event']['data']['pricing']['local'] ?? null;
        $paidAmountCents = MoneyAmount::majorToCents(is_array($local) ? ($local['amount'] ?? null) : null);
        if ($paidAmountCents === null
            || strtoupper((string) ($local['currency'] ?? '')) !== 'CNY') {
            throw new ApiException('charge amount is not reconcilable', 400);
        }

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
            array('X-CC-Api-Key:' . $this->getConfig('coinbase_api_key'), 'X-CC-Version: 2018-03-22')
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