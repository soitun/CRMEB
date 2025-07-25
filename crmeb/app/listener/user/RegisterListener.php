<?php


namespace app\listener\user;


use app\jobs\AgentJob;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\user\UserBillServices;
use app\services\user\UserServices;
use app\services\user\UserSpreadServices;
use crmeb\interfaces\ListenerInterface;

/**
 * 注册完成后置事件
 * Class RegisterListener
 * @package app\listener\user
 */
class RegisterListener implements ListenerInterface
{
    /**
     * 注册完成后置事件
     * @param $event
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function handle($event): void
    {
        [$spreadUid, $userType, $name, $uid, $isNew] = $event;

        if ($spreadUid) {
            if ($isNew) {
                //邀请新用户增加经验
                /** @var UserBillServices $userBill */
                $userBill = app()->make(UserBillServices::class);
                $userBill->inviteUserIncExp((int)$spreadUid);
                //增加推广佣金
                /** @var UserServices $userServices */
                $userServices = app()->make(UserServices::class);
                $userServices->addBrokeragePrice($uid, $spreadUid);

                //推广新人 处理自己、上级分销等级升级
                AgentJob::dispatch([$uid]);
            }
            //记录推广绑定关系
            /** @var UserSpreadServices $userSpreadServices */
            $userSpreadServices = app()->make(UserSpreadServices::class);
            $res = $userSpreadServices->setSpread($uid, $spreadUid);

            //自定义消息-下级用户绑定成功
            if ($res) {
                $phone = app()->make(UserServices::class)->value($uid, 'phone');
                event('CustomNoticeListener', [$spreadUid, ['nickname' => $name, 'time' => date('Y-m-d H:i:s'), 'phone' => $phone], 'spread_success']);
            }
        }

        if ($isNew) {
            //新人优惠券发送
            /**@var StoreCouponIssueServices $storeCoupon */
            $storeCoupon = app()->make(StoreCouponIssueServices::class);
            $storeCoupon->userFirstSubGiveCoupon((int)$uid);

            //人人分销开启推广权限
            if (sys_config('brokerage_func_status') && sys_config('store_brokerage_statu') == 2) {
                /** @var UserServices $userServices */
                $userServices = app()->make(UserServices::class);
                $userServices->update($uid, ['is_promoter' => 1]);
            }
        }
    }
}
