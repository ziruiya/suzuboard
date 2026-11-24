<?php

namespace Tests\Unit\Plugins;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__ . '/../../../plugins-core/Telegram/Plugin.php';

use Plugin\Telegram\Plugin;

class TelegramPaymentNotifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_notify_includes_user_id_and_email(): void
    {
        $user = User::create([
            'email' => 'buyer@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000101',
            'token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $payment = Payment::create([
            'payment' => 'EPay',
            'name' => '微信支付',
            'enable' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'payment_id' => $payment->id,
            'plan_id' => 1,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => \App\Models\Plan::PERIOD_MONTHLY,
            'trade_no' => '2026112411112628789914318',
            'total_amount' => 2900,
            'balance_amount' => 0,
            'status' => Order::STATUS_COMPLETED,
            'commission_status' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $captured = null;
        $service = $this->createMock(TelegramService::class);
        $service->expects($this->once())
            ->method('sendMessageWithAdmin')
            ->with($this->callback(function ($message) use (&$captured) {
                $captured = $message;
                return true;
            }), true);

        $plugin = new Plugin('Telegram');
        $prop = new \ReflectionProperty(Plugin::class, 'telegramService');
        $prop->setAccessible(true);
        $prop->setValue($plugin, $service);

        $plugin->sendPaymentNotify($order);

        $this->assertNotNull($captured);
        $this->assertStringContainsString('💰成功收款29元', $captured);
        $this->assertStringContainsString('支付接口：EPay', $captured);
        $this->assertStringContainsString('支付渠道：微信支付', $captured);
        $this->assertStringContainsString('本站订单：`2026112411112628789914318`', $captured);
        $this->assertStringContainsString("用户编号：{$user->id}", $captured);
        $this->assertStringContainsString('用户账号：buyer@example.com', $captured);
    }

    public function test_payment_notify_skips_when_user_missing(): void
    {
        $payment = Payment::create([
            'payment' => 'EPay',
            'name' => '微信支付',
            'enable' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order = Order::create([
            'user_id' => 999999,
            'payment_id' => $payment->id,
            'plan_id' => 1,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => \App\Models\Plan::PERIOD_MONTHLY,
            'trade_no' => 'orphan-order-1',
            'total_amount' => 100,
            'balance_amount' => 0,
            'status' => Order::STATUS_COMPLETED,
            'commission_status' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $service = $this->createMock(TelegramService::class);
        $service->expects($this->never())->method('sendMessageWithAdmin');

        $plugin = new Plugin('Telegram');
        $prop = new \ReflectionProperty(Plugin::class, 'telegramService');
        $prop->setAccessible(true);
        $prop->setValue($plugin, $service);

        $plugin->sendPaymentNotify($order);
    }
}
