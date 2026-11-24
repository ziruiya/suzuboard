<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\ServerGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Business plans batch 3: idempotently create the 「业务套餐-独享整机」
 * plan and its template group, OFF-SALE (show=0, sell=0). Run once at
 * deploy time; per D08 the plan only opens for sale once the in-panel
 * relay is ready. Kept out of the migration on purpose: seeding rows
 * into v2_plan / v2_server_group from a migration would shift the early
 * auto-increment ids (plan 6 was created the same operational way).
 */
class MachinePlanSeed extends Command
{
    protected $signature = 'suzu:machine-plan-seed';
    protected $description = 'Create the dedicated-VPS plan (业务套餐-独享整机) + template group, off-sale, idempotently';

    public const PLAN_NAME = '业务套餐-独享整机';
    public const TEMPLATE_GROUP_NAME = '业务-整机模板';

    public static function planContent(): string
    {
        return implode("\n", [
            '业务套餐-独享整机',
            '¥299/月｜每月 1TB｜专属 VPS 人工交付',
            '适合需要独立 VPS 运行环境、专属代理节点，并希望减少与其他客户共享服务器资源的业务用户。',
            '套餐包含：独享 VPS 1 台（为您单独采购、部署并分配，不与其他客户共用该 VPS 实例）；专属代理线路 3 条（三种不同协议，均位于您所选地区的这台 VPS 上）；全部当前可用、按套餐授权的 VIP 共享线路；每月 1TB 套餐流量额度。',
            '可选部署地区：韩国、日本、新加坡、美国、德国、英国、台湾、香港。购买时选择一个地区，系统按所选地区安排采购和部署。本套餐仅限您购买时所选的这一个地区，整台机器归您一人独享。',
            '与独享端口有什么区别？独享端口套餐是在共享服务器上，为您分配专属入口端口及主备线路。独享整机套餐则为您单独分配一台 VPS，拥有独立的虚拟服务器实例和专属节点，不与其他客户共用这台 VPS。',
            '开通方式：本套餐采用人工采购与自动化部署相结合的方式，以付款确认后 3 小时内完成交付为目标。交付完成以专属 VPS 上线、节点连接通过验收、客户订阅可正常使用为准，并非仅以 VPS 采购成功为准。若因地区供应不足、安装故障或其他原因超过 3 小时仍未交付，我们会主动通知您当前状态，并提供以下选择：继续等待原地区部署；免费改选其他当前可供应地区。（本站全部套餐一经购买不设退款，本套餐同样适用。）',
            '续费与到期：套餐有效期内正常续费，优先保留原 VPS 与节点配置。套餐到期后停止连接权限，原 VPS 最多保留 48 小时供续费恢复。超过保留期未续费，服务器将进入资源回收流程；原 VPS、IP 和节点配置不再保证保留。',
            '不包含：第二台独立备用 VPS；独享物理服务器；VPS root 或系统管理员权限；VPS 所有权转移；无限制流量、独占物理带宽或固定速度保证；指定云服务商、永久固定 IP 或永久不变的硬件规格。',
            '专属 VPS 节点和 VIP 共享线路共同使用每月 1TB 套餐流量额度。推荐设备上限为 2 台，最终以购买页实际配置为准。',
        ]);
    }

    public function handle(): int
    {
        $result = DB::transaction(function () {
            $group = ServerGroup::where('name', self::TEMPLATE_GROUP_NAME)->first();
            if (!$group) {
                $group = new ServerGroup();
                $group->name = self::TEMPLATE_GROUP_NAME;
                $group->save();
            }

            $plan = Plan::where('name', self::PLAN_NAME)->first();
            if (!$plan) {
                $plan = Plan::create([
                    'group_id' => $group->id,
                    'transfer_enable' => 1024,
                    'name' => self::PLAN_NAME,
                    'speed_limit' => null,
                    'show' => false,
                    'sort' => 7,
                    'renew' => true,
                    'content' => self::planContent(),
                    'prices' => [Plan::PERIOD_MONTHLY => 299],
                    'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
                    'capacity_limit' => null,
                    'sell' => false,
                    'device_limit' => 2,
                    'business_tier' => 'machine',
                    'machine_region_required' => 1,
                ]);
                return ['created', $plan->id, $group->id];
            }

            // Idempotent repair of the markers; never touches show/sell.
            $plan->business_tier = 'machine';
            $plan->machine_region_required = 1;
            $plan->save();
            return ['exists', $plan->id, $group->id];
        });

        $this->info("machine plan {$result[0]}: plan_id={$result[1]} template_group_id={$result[2]} (off-sale)");
        return self::SUCCESS;
    }
}
