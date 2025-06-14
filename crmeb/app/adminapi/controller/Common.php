<?php
// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2023 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------
namespace app\adminapi\controller;

use app\services\system\config\SystemConfigServices;
use app\services\system\config\SystemConfigTabServices;
use app\services\system\SystemAuthServices;
use app\services\order\StoreOrderServices;
use app\services\product\product\StoreProductServices;
use app\services\product\product\StoreProductReplyServices;
use app\services\system\UpgradeServices;
use app\services\user\UserExtractServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\system\SystemMenusServices;
use app\services\user\UserServices;
use crmeb\services\CacheService;
use crmeb\services\HttpService;
use think\facade\Config;

/**
 * 公共接口基类 主要存放公共接口
 * Class Common
 * @package app\adminapi\controller
 */
class Common extends AuthController
{
    /**
     * 获取logo
     * @return mixed
     */
    public function getLogo()
    {
        return app('json')->success([
            'logo' => sys_config('site_logo'),
            'logo_square' => sys_config('site_logo_square'),
            'site_name' => sys_config('site_name')
        ]);
    }

    /**
     * 获取授权信息
     * @return mixed
     */
    public function auth()
    {
        $version = get_crmeb_version();
        $host = $this->request->host();
        // 正常域名
        $res = HttpService::request('http://authorize.crmeb.net/api/auth_cert_query', 'post', [
            'domain_name' => $host,
            'label' => 34,
            'version' => $version
        ]);
        $res = $res ? json_decode($res, true) : [];
        $status = $res['data']['status'] ?? -9;
        switch ((int)$status) {
            case 1:
                //审核成功
                $authCode = $res['data']['auth_code'] ?? '';
                $autoContent = $res['data']['auto_content'] ?? '';
                try {
                    /** @var SystemConfigServices $services */
                    $services = app()->make(SystemConfigServices::class);
                    if ($services->count(['menu_name' => 'cert_crmeb'])) {
                        $services->update(['menu_name' => 'cert_crmeb'], ['value' => json_encode($autoContent . ',' . $authCode)]);
                    } else {
                        $services->save([
                            'menu_name' => 'cert_crmeb',
                            'type' => 'text',
                            'input_type' => 'input',
                            'config_tab_id' => 1,
                            'value' => json_encode($autoContent . ',' . $authCode),
                            'status' => 2,
                            'info' => '授权密钥'
                        ]);
                    }
                } catch (\Throwable $e) {
                    return app('json')->fail(400330);
                }
                return app('json')->success(['status' => 1, 'copyright' => $res['data']['copyright'], 'authCode' => $authCode, 'day' => 0, 'force_reminder' => $upgradeStatus['force_reminder'] ?? 0]);
            default:
                return app('json')->success(['status' => -9, 'force_reminder' => $upgradeStatus['force_reminder'] ?? 0]);
        }
    }

    /**
     * 申请授权
     * @return mixed
     */
    public function auth_apply(SystemAuthServices $services)
    {
        $data = $this->request->postMore([
            ['company_name', ''],
            ['domain_name', ''],
            ['order_id', ''],
            ['phone', ''],
            ['label', 19],
            ['captcha', ''],
        ]);
        if (!$data['company_name']) {
            return app('json')->fail(400331);
        }
        if (!$data['domain_name']) {
            return app('json')->fail(400332);
        }

        if (!$data['phone']) {
            return app('json')->fail(400333);
        }
        if (!$data['order_id']) {
            return app('json')->fail(400334);
        }
        if (!$data['captcha']) {
            return app('json')->fail(400137);
        }
        $services->authApply($data);
        return app('json')->success(400335);

    }

    /**
     * 首页头部统计数据
     * @return mixed
     */
    public function homeStatics()
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $info = $orderServices->homeStatics();
        return app('json')->success(compact('info'));
    }

    /**
    * 计算增长率
    * 特殊情况：
    * 1. 当前值和上期值均为0时，返回0；
    * 2. 上期值为0时，返回当前值；
    * 3. 当前值为0时，返回上期值的负数。
    *
    * @param float $nowValue 当前值
    * @param float $lastValue 上期值
    * @return float 增长率
    */
    public function growth($nowValue, $lastValue)
    {
       if ($lastValue == 0 && $nowValue == 0) return 0;
       if ($lastValue == 0) return round($nowValue, 2);
       if ($nowValue == 0) return -round($lastValue, 2);
       return bcmul(bcdiv((bcsub($nowValue, $lastValue, 2)), $lastValue, 2), 100, 2);
    }


    /**
     * 订单图表
     */
    public function orderChart()
    {
        $cycle = $this->request->param('cycle') ?: 'thirtyday';//默认30天
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $chartdata = $orderServices->orderCharts($cycle);
        return app('json')->success($chartdata);
    }

    /**
     * 用户图表
     */
    public function userChart()
    {
        /** @var UserServices $uServices */
        $uServices = app()->make(UserServices::class);
        $chartdata = $uServices->userChart();
        return app('json')->success($chartdata);
    }

    /**
     * 交易额排行
     * @return mixed
     */
    public function purchaseRanking()
    {
        /** @var StoreProductAttrValueServices $valueServices */
        $valueServices = app()->make(StoreProductAttrValueServices::class);
        $list = $valueServices->purchaseRanking();
        return app('json')->success(compact('list'));
    }

    /**
     * 待办事统计
     * @return mixed
     */
    public function jnotice()
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $data['ordernum'] = $orderServices->storeOrderCount();
        $store_stock = sys_config('store_stock');
        if ($store_stock < 0) $store_stock = 2;
        /** @var StoreProductServices $storeServices */
        $storeServices = app()->make(StoreProductServices::class);
        $data['inventory'] = $storeServices->count(['type' => 5, 'store_stock' => $store_stock]);//警戒库存
        /** @var StoreProductReplyServices $replyServices */
        $replyServices = app()->make(StoreProductReplyServices::class);
        $data['commentnum'] = $replyServices->replyCount();
        /** @var UserExtractServices $extractServices */
        $extractServices = app()->make(UserExtractServices::class);
        $data['reflectnum'] = $extractServices->userExtractCount();//提现
        $data['msgcount'] = intval($data['ordernum']) + intval($data['inventory']) + intval($data['commentnum']) + intval($data['reflectnum']);
        $data['newOrderId'] = $orderServices->newOrderId(1);
        if (count($data['newOrderId'])) $orderServices->newOrderUpdate($data['newOrderId']);
        $value = [];
        if ($data['ordernum'] != 0) {
            $value[] = [
                'title' => "您有$data[ordernum]个待发货的订单",
                'type' => 1,
                'url' => '/' . Config::get('app.admin_prefix', 'admin') . '/order/list?status=1'
            ];
        }
        if ($data['inventory'] != 0) {
            $value[] = [
                'title' => "您有$data[inventory]个商品库存预警",
                'type' => 2,
                'url' => '/' . Config::get('app.admin_prefix', 'admin') . '/product/product_list?type=5',
            ];
        }
        if ($data['commentnum'] != 0) {
            $value[] = [
                'title' => "您有$data[commentnum]条评论待回复",
                'type' => 3,
                'url' => '/' . Config::get('app.admin_prefix', 'admin') . '/product/product_reply?is_reply=0'
            ];
        }
        if ($data['reflectnum'] != 0) {
            $value[] = [
                'title' => "您有$data[reflectnum]个提现申请待审核",
                'type' => 4,
                'url' => '/' . Config::get('app.admin_prefix', 'admin') . '/finance/user_extract/index?status=0',
            ];
        }
        return app('json')->success($this->noticeData($value));
    }

    /**
     * 消息返回格式
     * @param array $data
     * @return array
     */
    public function noticeData(array $data): array
    {
        // 消息图标
        $iconColor = [
            // 邮件 消息
            'mail' => [
                'icon' => 'md-mail',
                'color' => '#3391e5'
            ],
            // 普通 消息
            'bulb' => [
                'icon' => 'md-bulb',
                'color' => '#87d068'
            ],
            // 警告 消息
            'information' => [
                'icon' => 'md-information',
                'color' => '#fe5c57'
            ],
            // 关注 消息
            'star' => [
                'icon' => 'md-star',
                'color' => '#ff9900'
            ],
            // 申请 消息
            'people' => [
                'icon' => 'md-people',
                'color' => '#f06292'
            ],
        ];
        // 消息类型
        $type = array_keys($iconColor);
        // 默认数据格式
        $default = [
            'icon' => 'md-bulb',
            'iconColor' => '#87d068',
            'title' => '',
            'url' => '',
            'type' => 'bulb',
            'read' => 0,
            'time' => 0
        ];
        $value = [];
        foreach ($data as $item) {
            $val = array_merge($default, $item);
            if (isset($item['type']) && in_array($item['type'], $type)) {
                $val['type'] = $item['type'];
                $val['iconColor'] = $iconColor[$item['type']]['color'] ?? '';
                $val['icon'] = $iconColor[$item['type']]['icon'] ?? '';
            }
            $value[] = $val;
        }
        return $value;
    }

    /**
     * 格式化菜单
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function menusList()
    {
        /** @var SystemMenusServices $menusServices */
        $menusServices = app()->make(SystemMenusServices::class);
        $list = $menusServices->getSearchList();
        $counts = $menusServices->getColumn([
            ['is_show', '=', 1],
            ['auth_type', '=', 1],
            ['is_del', '=', 0],
            ['is_show_path', '=', 0],
        ], 'pid');
        $data = [];
        foreach ($list as $key => $item) {
            $pid = $item->getData('pid');
            $data[$key] = json_decode($item, true);
            $data[$key]['pid'] = $pid;
            $data[$key]['menu_path'] = '/' . config('app.admin_prefix', 'admin') . $item['menu_path'];
            if (in_array($item->id, $counts)) {
                $data[$key]['type'] = 1;
            } else {
                $data[$key]['type'] = 0;
            }
        }
        return app('json')->success(sort_list_tier($data));
    }

    /**
     * 查询购买版权
     * @return mixed
     */
    public function copyright()
    {
        $copyrightContext = sys_config('nncnL_crmeb_copyright', '');
        $copyrightImage = sys_config('nncnL_crmeb_copyright_image', '');
        return app('json')->success(compact('copyrightContext', 'copyrightImage'));
    }

    /**
     * 保存版权
     * @return mixed
     */
    public function saveCopyright()
    {
        [$copyright, $copyrightImg] = $this->request->postMore(['copyright', 'copyright_img',], true);
        /** @var SystemConfigServices $services */
        $services = app()->make(SystemConfigServices::class);
        if ($services->count(['menu_name' => 'nncnL_crmeb_copyright'])) {
            $services->update(['menu_name' => 'nncnL_crmeb_copyright'], ['value' => json_encode($copyright)]);
        } else {
            $services->save([
                'menu_name' => 'nncnL_crmeb_copyright',
                'type' => 'text',
                'input_type' => 'input',
                'config_tab_id' => 1,
                'value' => json_encode($copyright),
                'status' => 2,
                'info' => ''
            ]);
        }
        if ($services->count(['menu_name' => 'nncnL_crmeb_copyright_image'])) {
            $services->update(['menu_name' => 'nncnL_crmeb_copyright_image'], ['value' => json_encode($copyrightImg)]);
        } else {
            $services->save([
                'menu_name' => 'nncnL_crmeb_copyright_image',
                'type' => 'text',
                'input_type' => 'input',
                'config_tab_id' => 1,
                'value' => json_encode($copyrightImg),
                'status' => 2,
                'info' => ''
            ]);
        }
        CacheService::clear();
        return app('json')->success(100000);
    }

    /**
     * 系统搜索菜单
     * @return \think\Response
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2024/2/1
     */
    public function menusSearch()
    {
        // 从请求中获取关键字
        [$keyword] = $this->request->postMore([
            ['keyword', ''],
        ], true);
        if (empty($keyword)) {
           return app('json')->fail(400239, '关键字不能为空');
        }

        // 获取系统菜单服务实例
        $menusServices = app()->make(SystemMenusServices::class);
        // 查询菜单列表
        $menusList = $menusServices->selectList([['menu_name', 'like', '%' . $keyword . '%'], ['auth_type', '=', 1]], 'menu_name as title,menu_path as path,id')->toArray();
        // 获取系统配置服务实例
        $configServices = app()->make(SystemConfigServices::class);
        // 获取系统配置标签服务实例
        $configTabServices = app()->make(SystemConfigTabServices::class);
        // 查询配置项列表
        $configList = $configServices->selectList([['info', 'like', '%' . $keyword . '%']], 'info as title,config_tab_id')->toArray();
        // 查询配置项标签列表
        $configTabList = $configTabServices->selectList([['title', 'like', '%' . $keyword . '%']], 'title,id as config_tab_id')->toArray();
        // 合并配置项列表和配置项标签列表
        $configAllList = array_merge($configList, $configTabList);
        // 获取配置项对应的标签ID
        $tabIds = array_unique(array_column($configAllList, 'config_tab_id'));
        // 查询配置项标签列表
        $tabList = $configTabServices->getColumn([['id', 'in', $tabIds]], 'menus_id', 'id');

        // 将配置项标签列表中的菜单ID与配置项列表中的菜单ID对应起来
        foreach ($configAllList as &$item1) {
            $item1['menus_id'] = $tabList[$item1['config_tab_id']] ?? 0;
        }
        // 获取配置项标签对应的菜单ID
        $configTabIds = array_values($tabList);
        // 查询配置项标签对应的菜单列表
        $configMenusList = $menusServices->getColumn([['id', 'in', $configTabIds]], 'menu_name as title,menu_path as path,id', 'id');

        // 将配置项列表中的菜单ID与配置项标签对应的菜单ID对应起来
        foreach ($configAllList as $item2) {
            if ($item2['menus_id'] == 0) {
                continue;
            }
            $menusList[] = [
                'title' => $configMenusList[$item2['menus_id']]['title'] . '-' . $item2['title'],
                'path' => $configMenusList[$item2['menus_id']]['path'] . '?tab_id=' . $item2['config_tab_id'],
                'id' => $configMenusList[$item2['menus_id']]['id']
            ];
        }
        // 将菜单列表中的路径前缀添加到每个菜单项的 path 属性上
        $adminPrefix = '/' . Config::get('app.admin_prefix', 'admin');
        foreach ($menusList as &$item) {
           if (strpos($item['path'], '/') !== 0) {
               $item['path'] = $adminPrefix . '/' . ltrim($item['path'], '/');
           } else {
               $item['path'] = $adminPrefix . $item['path'];
           }
        }
        // 返回 JSON 格式的菜单列表
        return app('json')->success($menusList);
    }
}
