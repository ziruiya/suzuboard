<?php

namespace App\Services\Machine;

use App\Models\MachineFulfillment;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Support\Facades\Log;

/**
 * Customer-facing notices for the dedicated-VPS plan, drafted from the
 * finalised §3.3 rules as amended by D08: the overdue notice offers
 * exactly two choices — keep waiting for the original region, or switch
 * free of charge to another region that can be supplied. No notice in
 * this flow ever mentions a refund, and no code path here creates one.
 * Mail failures are logged, never allowed to break the state machine.
 */
class MachineNotificationService
{
    public function overdueNotice(MachineFulfillment $f): void
    {
        $content = implode("\n", [
            '您购买的「业务套餐-独享整机」正在部署中。',
            '所选地区：' . $f->region_name . '。目前已超过原定的 3 小时交付目标，仍在处理中，给您带来不便十分抱歉。',
            '您可以选择：',
            '1）继续等待原地区部署：我们会继续为您采购部署，最长再等待 24 小时，期间每 6 小时向您同步一次进度；',
            '2）免费改选其他地区：可改选当前可供应的其他地区，费用不变。',
            '请登录网站，在首页「我的订阅」中作出选择；如未选择，我们会继续按原地区推进并同步进度。',
        ]);
        $this->send($f, '您的独享整机部署进度说明', $content);
    }

    public function progressUpdate(MachineFulfillment $f): void
    {
        $content = implode("\n", [
            '您的「业务套餐-独享整机」（地区：' . $f->region_name . '）部署进度更新：当前状态为「' . $f->status . '」，我们仍在继续处理。',
            '如需改选其他可供应地区，可在首页「我的订阅」中操作。',
        ]);
        $this->send($f, '您的独享整机部署进度更新', $content);
    }

    public function delivered(MachineFulfillment $f): void
    {
        $content = implode("\n", [
            '您的「业务套餐-独享整机」已交付完成。',
            '部署地区：' . $f->region_name . '，专属 VPS 已上线，3 条专属线路与全部授权 VIP 线路均已开通。',
            '请在客户端更新订阅后使用。如需更换端口，可在首页「我的订阅」中使用「更换端口」（每 2 小时可更换一次）。',
        ]);
        $this->send($f, '您的独享整机已交付', $content);
    }

    private function send(MachineFulfillment $f, string $subject, string $content): void
    {
        try {
            $user = User::find($f->user_id);
            if (!$user || !$user->email) {
                return;
            }
            MailService::sendEmail([
                'email' => $user->email,
                'subject' => $subject,
                'template_name' => 'notify',
                'template_value' => [
                    'name' => admin_setting('app_name', 'XBoard'),
                    'content' => $content,
                    'url' => admin_setting('app_url'),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('machine fulfilment mail failed', [
                'fulfillment_id' => $f->id,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
