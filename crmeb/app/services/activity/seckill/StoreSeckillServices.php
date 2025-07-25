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

namespace app\services\activity\seckill;

use app\Request;
use app\services\activity\StoreActivityServices;
use app\services\BaseServices;
use app\dao\activity\seckill\StoreSeckillDao;
use app\services\order\StoreOrderServices;
use app\services\other\QrcodeServices;
use app\services\product\product\StoreCategoryServices;
use app\services\product\product\StoreDescriptionServices;
use app\services\product\product\StoreProductRelationServices;
use app\services\product\product\StoreProductReplyServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrResultServices;
use app\services\product\sku\StoreProductAttrServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\system\config\SystemGroupDataServices;
use crmeb\exceptions\AdminException;
use app\jobs\ProductLogJob;
use crmeb\exceptions\ApiException;
use crmeb\services\CacheService;
use crmeb\utils\Arr;

/**
 *
 * Class StoreSeckillServices
 * @package app\services\activity
 * @method getSeckillIdsArray(array $ids, array $field)
 * @method get(int $id, array $field) 获取一条数据
 */
class StoreSeckillServices extends BaseServices
{

    /**
     * StoreSeckillServices constructor.
     * @param StoreSeckillDao $dao
     */
    public function __construct(StoreSeckillDao $dao)
    {
        $this->dao = $dao;
    }

    public function getCount(array $where)
    {
        $this->dao->count($where);
    }

    /**
     * 秒杀是否存在
     * @param int $id
     * @return int
     */
    public function getSeckillCount(int $id = 0, string $field = 'time_id')
    {
        $where = [];
        $where[] = ['is_del', '=', 0];
        $where[] = ['status', '=', 1];
        if ($id) {
            $time = time();
            $where[] = ['id', '=', $id];
            $where[] = ['start_time', '<=', $time];
            $where[] = ['stop_time', '>=', $time - 86400];
            return $this->dao->getOne($where, $field);
        } else {
            $seckillTime = sys_data('routine_seckill_time') ?: [];//秒杀时间段
            $timeInfo = ['time' => 0, 'continued' => 0];
            foreach ($seckillTime as $key => $value) {
                $currentHour = date('H');
                $activityEndHour = (int)$value['time'] + (int)$value['continued'];
                if ($currentHour >= (int)$value['time'] && $currentHour < $activityEndHour && $activityEndHour < 24) {
                    $timeInfo = $value;
                    break;
                }
            }
            if ($timeInfo['time'] == 0) return 0;
            $activityEndHour = $timeInfo['time'] + (int)$timeInfo['continued'];
            $startTime = strtotime(date('Y-m-d')) + (int)$timeInfo['time'] * 3600;
            $stopTime = strtotime(date('Y-m-d')) + (int)$activityEndHour * 3600;

            $where[] = ['start_time', '<', $startTime];
            $where[] = ['stop_time', '>', $stopTime];
            return $this->dao->getCount($where);
        }
    }


    /**
     * 保存数据
     * @param int $id
     * @param array $data
     */
    public function saveData(int $id, array $data)
    {
        if ($data['section_time']) {
            [$start_time, $end_time] = $data['section_time'];
            if (strtotime($end_time) + 86400 < time()) {
                throw new AdminException(400507);
            }
        }

        $seckill = [];
        if ($id) {
            $seckill = $this->get((int)$id);
            if (!$seckill) {
                throw new AdminException(100026);
            }
        }
        //限制编辑
        if ($data['copy'] == 0 && $seckill) {
            if ($seckill['stop_time'] + 86400 < time()) {
                throw new AdminException(400508);
            }
        }
        if ($data['num'] < $data['once_num']) {
            throw new AdminException(400500);
        }
        if ($data['copy'] == 1) {
            $id = 0;
            unset($data['copy']);
        }

        $description = $data['description'];
        $detail = $data['attrs'];
        $items = $data['items'];
        $data['start_time'] = strtotime($data['section_time'][0]);
        $data['stop_time'] = strtotime($data['section_time'][1]);
        $data['image'] = $data['images'][0];
        $data['images'] = json_encode($data['images']);
        $data['price'] = min(array_column($detail, 'price'));
        $data['ot_price'] = min(array_column($detail, 'ot_price'));
        $data['quota'] = $data['quota_show'] = array_sum(array_column($detail, 'quota'));
        $data['stock'] = array_sum(array_column($detail, 'stock'));
        $data['logistics'] = implode(',', $data['logistics']);
        $data['time_id'] = implode(',', $data['time_id']);
        unset($data['section_time'], $data['description'], $data['attrs'], $data['items']);
        /** @var StoreDescriptionServices $storeDescriptionServices */
        $storeDescriptionServices = app()->make(StoreDescriptionServices::class);
        /** @var StoreProductAttrServices $storeProductAttrServices */
        $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
        /** @var StoreProductServices $storeProductServices */
        $storeProductServices = app()->make(StoreProductServices::class);
        if ($data['quota'] > $storeProductServices->value(['id' => $data['product_id']], 'stock')) {
            throw new AdminException(400090);
        }
        $this->transaction(function () use ($id, $data, $description, $detail, $items, $storeDescriptionServices, $storeProductAttrServices, $storeProductServices) {
            if ($id) {
                $res = $this->dao->update($id, $data);
                $storeDescriptionServices->saveDescription((int)$id, $description, 1);
                $skuList = $storeProductServices->validateProductAttr($items, $detail, (int)$id, 1);
                $valueGroup = $storeProductAttrServices->saveProductAttr($skuList, (int)$id, 1);
                if (!$res) throw new AdminException(100007);
            } else {
                if (!$storeProductServices->getOne(['is_del' => 0, 'id' => $data['product_id']])) {
                    throw new AdminException('无法添加回收站商品');
                }
                $data['add_time'] = time();
                $res = $this->dao->save($data);
                $storeDescriptionServices->saveDescription((int)$res->id, $description, 1);
                $skuList = $storeProductServices->validateProductAttr($items, $detail, (int)$res->id, 1, 1, true);
                $valueGroup = $storeProductAttrServices->saveProductAttr($skuList, (int)$res->id, 1);
                if (!$res) throw new AdminException(100022);
            }
        });
    }

    /**
     * 获取列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function systemPage(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, $page, $limit);
        $count = $this->dao->count($where + ['is_del' => 0]);
        $activityIds = array_unique(array_column($list, 'activity_id'));
        $activityInfos = app()->make(StoreActivityServices::class)->getColumn(['id' => $activityIds], 'title', 'id');
        $stopIds = [];
        foreach ($list as &$item) {
            $item['store_name'] = $item['title'];
            if ($item['status']) {
                if ($item['start_time'] > time()) {
                    $item['start_name'] = '未开始';
                } else if (bcadd($item['stop_time'], '86400') < time()) {
                    $item['start_name'] = '已结束';
                    $item['status'] = 0;
                    $stopIds[] = $item['id'];
                } else if (bcadd($item['stop_time'], '86400') > time() && $item['start_time'] < time()) {
                    $item['start_name'] = '进行中';
                }
            } else $item['start_name'] = '已结束';
            $end_time = $item['stop_time'] ? date('Y/m/d', (int)$item['stop_time']) : '';
            $item['_stop_time'] = $end_time;
            $item['stop_status'] = $item['stop_time'] + 86400 < time() ? 1 : 0;
            $item['start_time'] = date('Y-m-d H:i:s', $item['start_time']);
            $item['stop_time'] = date('Y-m-d 23:59:59', $item['stop_time']);
            $item['activity_name'] = $activityInfos[$item['activity_id']] ?? '';
        }
        if ($stopIds) {
            $this->dao->batchUpdate($stopIds, ['status' => 0]);
        }
        return compact('list', 'count');
    }

    /**
     * 后台页面设计获取商品列表
     * @param $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getDiySeckillList($where)
    {
        unset($where['is_show']);
        $where['storeProductId'] = true;
        $where['status'] = 1;
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, $page, $limit);
        $count = $this->dao->getCount($where);
        $cateIds = implode(',', array_column($list, 'cate_id'));
        /** @var StoreCategoryServices $storeCategoryServices */
        $storeCategoryServices = app()->make(StoreCategoryServices::class);
        $cateList = $storeCategoryServices->getCateArray($cateIds);
        foreach ($list as &$item) {
            $cateName = '';
            $item['cate_name'] = '';
            if ($item['cate_id']) {
                $cateName = array_filter($cateList, function ($val) use ($item) {
                    if (in_array($val['id'], explode(',', $item['cate_id']))) {
                        return $val;
                    }
                });
                $item['cate_name'] = implode(',', array_column($cateName, 'cate_name'));
            }
            $item['store_name'] = $item['title'];
            $item['price'] = floatval($item['price']);
            $item['is_product_type'] = 3;
        }
        return compact('count', 'list');

    }

    /**
     * 首页秒杀数据
     * @param $where
     * @return array|int
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getHomeSeckillList($where)
    {
        $data = [];
        $seckillTime = sys_data('routine_seckill_time') ?: [];//秒杀时间段
        $today = strtotime(date('Y-m-d'));
        $timeInfo = ['time' => 0, 'continued' => 0];
        foreach ($seckillTime as $key => $value) {
            $currentHour = date('H');
            $activityEndHour = (int)$value['time'] + (int)$value['continued'];
            if ($currentHour >= (int)$value['time'] && $currentHour < $activityEndHour && $activityEndHour <= 24) {
                $timeInfo = $value;
                break;
            }
        }
        if ($timeInfo['time'] == 0) return [];
        $data['time'] = $timeInfo['time'] . ':00';
        $activityEndHour = bcadd($timeInfo['time'], $timeInfo['continued'], 0);
        $data['stop'] = (int)bcadd((string)$today, bcmul($activityEndHour, '3600', 0));
        $where['time_id'] = $timeInfo['id'];
        [$page, $limit] = $this->getPageValue();
        $data['list'] = $this->dao->getHomeList($where, $page, $limit);
        foreach ($data['list'] as &$item) {
            $item['price'] = floatval($item['price']);
        }
        return $data;

    }

    /**
     * 获取秒杀详情
     * @param int $id
     * @return array|\think\Model|null
     */
    public function getInfo(int $id)
    {
        $info = $this->dao->get($id);
        if ($info) {
            if ($info['start_time'])
                $start_time = date('Y-m-d', (int)$info['start_time']);

            if ($info['stop_time'])
                $stop_time = date('Y-m-d', (int)$info['stop_time']);
            if (isset($start_time) && isset($stop_time))
                $info['section_time'] = [$start_time, $stop_time];
            else
                $info['section_time'] = [];
            unset($info['start_time'], $info['stop_time']);
            $info['give_integral'] = intval($info['give_integral']);
            $info['price'] = floatval($info['price']);
            $info['ot_price'] = floatval($info['ot_price']);
            $info['postage'] = floatval($info['postage']);
            $info['cost'] = floatval($info['cost']);
            $info['weight'] = floatval($info['weight']);
            $info['volume'] = floatval($info['volume']);
            $info['logistics'] = explode(',', $info['logistics']);
            /** @var StoreDescriptionServices $storeDescriptionServices */
            $storeDescriptionServices = app()->make(StoreDescriptionServices::class);
            $info['description'] = $storeDescriptionServices->getDescription(['product_id' => $id, 'type' => 1]);
            $info['attrs'] = $this->attrList($id, $info['product_id']);
            $info['time_id'] = strpos($info['time_id'], ',') === false ? [(int)$info['time_id']] : array_map('intval', explode(',', $info['time_id']));
        }
        return $info;
    }

    /**
     * 获取规格
     * @param int $id
     * @param int $pid
     * @return mixed
     */
    public function attrList(int $id, int $pid)
    {
        /** @var StoreProductAttrResultServices $storeProductAttrResultServices */
        $storeProductAttrResultServices = app()->make(StoreProductAttrResultServices::class);
        $seckillResult = $storeProductAttrResultServices->value(['product_id' => $id, 'type' => 1], 'result');
        $items = json_decode($seckillResult, true)['attr'];
        $productAttr = $this->getAttr($items, $pid, 0);
        $seckillAttr = $this->getAttr($items, $id, 1);
        foreach ($productAttr as $pk => $pv) {
            foreach ($seckillAttr as &$sv) {
                if ($pv['detail'] == $sv['detail']) {
                    $productAttr[$pk] = $sv;
                }
            }
            $productAttr[$pk]['detail'] = json_decode($productAttr[$pk]['detail']);
        }
        $attrs['items'] = $items;
        $attrs['value'] = $productAttr;
        foreach ($items as $key => $item) {
            $header[] = ['title' => $item['value'], 'key' => 'value' . ($key + 1), 'align' => 'center', 'minWidth' => 80];
        }
        $header[] = ['title' => '图片', 'slot' => 'pic', 'align' => 'center', 'minWidth' => 120];
        $header[] = ['title' => '秒杀价', 'slot' => 'price', 'align' => 'center', 'minWidth' => 80];
        $header[] = ['title' => '成本价', 'key' => 'cost', 'align' => 'center', 'minWidth' => 80];
        $header[] = ['title' => '划线价', 'key' => 'ot_price', 'align' => 'center', 'minWidth' => 80];
        $header[] = ['title' => '库存', 'key' => 'stock', 'align' => 'center', 'minWidth' => 80];
        $header[] = ['title' => '限量', 'slot' => 'quota', 'type' => 1, 'align' => 'center', 'minWidth' => 80];
        $header[] = ['title' => '重量(KG)', 'key' => 'weight', 'align' => 'center', 'minWidth' => 80];
        $header[] = ['title' => '体积(m³)', 'key' => 'volume', 'align' => 'center', 'minWidth' => 80];
        $header[] = ['title' => '商品编码', 'key' => 'bar_code', 'align' => 'center', 'minWidth' => 80];
        $header[] = ['title' => '条形码', 'key' => 'bar_code_number', 'align' => 'center', 'minWidth' => 80];
        $attrs['header'] = $header;
        return $attrs;
    }

    /**
     * 获取规格
     * @param $attr
     * @param $id
     * @param $type
     * @return array
     */
    public function getAttr($attr, $id, $type)
    {
        /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
        $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
        list($value, $head) = attr_format($attr);
        $valueNew = [];
        $count = 0;
        foreach ($value as $suk) {
            $detail = explode(',', $suk);
            $sukValue = $storeProductAttrValueServices->getColumn(['product_id' => $id, 'type' => $type, 'suk' => $suk], 'bar_code,bar_code_number,cost,price,ot_price,stock,image as pic,weight,volume,brokerage,brokerage_two,quota', 'suk');
            if (count($sukValue)) {
                foreach ($detail as $k => $v) {
                    $valueNew[$count]['value' . ($k + 1)] = $v;
                }
                $valueNew[$count]['detail'] = json_encode(array_combine($head, $detail));
                $valueNew[$count]['pic'] = $sukValue[$suk]['pic'] ?? '';
                $valueNew[$count]['price'] = $sukValue[$suk]['price'] ? floatval($sukValue[$suk]['price']) : 0;
                $valueNew[$count]['cost'] = $sukValue[$suk]['cost'] ? floatval($sukValue[$suk]['cost']) : 0;
                $valueNew[$count]['ot_price'] = isset($sukValue[$suk]['ot_price']) ? floatval($sukValue[$suk]['ot_price']) : 0;
                $valueNew[$count]['stock'] = $sukValue[$suk]['stock'] ? intval($sukValue[$suk]['stock']) : 0;
                $valueNew[$count]['quota'] = $sukValue[$suk]['quota'] ? intval($sukValue[$suk]['quota']) : 0;
                $valueNew[$count]['bar_code'] = $sukValue[$suk]['bar_code'] ?? '';
                $valueNew[$count]['bar_code_number'] = $sukValue[$suk]['bar_code_number'] ?? '';
                $valueNew[$count]['weight'] = $sukValue[$suk]['weight'] ? floatval($sukValue[$suk]['weight']) : 0;
                $valueNew[$count]['volume'] = $sukValue[$suk]['volume'] ? floatval($sukValue[$suk]['volume']) : 0;
                $valueNew[$count]['brokerage'] = $sukValue[$suk]['brokerage'] ? floatval($sukValue[$suk]['brokerage']) : 0;
                $valueNew[$count]['brokerage_two'] = $sukValue[$suk]['brokerage_two'] ? floatval($sukValue[$suk]['brokerage_two']) : 0;
                $valueNew[$count]['_checked'] = $type != 0;
                $count++;
            }
        }
        return $valueNew;
    }

    /**
     * 获取某个时间段的秒杀列表
     * @param int $time
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getListByTime(int $time)
    {
        [$page, $limit] = $this->getPageValue();
        $seckillInfo = $this->dao->getListByTime($time, $page, $limit);
        if (count($seckillInfo)) {
            foreach ($seckillInfo as $key => &$item) {
                if ($item['quota'] > 0) {
                    $percent = (int)(($item['quota_show'] - $item['quota']) / $item['quota_show'] * 100);
                    $item['percent'] = $percent;
                    $item['stock'] = $item['quota'];
                } else {
                    $item['percent'] = 100;
                    $item['stock'] = 0;
                }
                $item['price'] = floatval($item['price']);
                $item['ot_price'] = floatval($item['ot_price']);
            }
        }
        return $seckillInfo;
    }

    /**
     * 获取秒杀详情
     * @param Request $request
     * @param int $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function seckillDetail(Request $request, int $id, int $time_id = 0)
    {
        $uid = (int)$request->uid();
        $storeInfo = $this->dao->getOne(['id' => $id], '*', ['description', 'product']);
        if (!$storeInfo) {
            throw new ApiException(410294);
        } else {
            $storeInfo = $storeInfo->toArray();
        }
        $siteUrl = sys_config('site_url');
        $storeInfo['image'] = set_file_url($storeInfo['image'], $siteUrl);
        $storeInfo['image_base'] = set_file_url($storeInfo['image'], $siteUrl);
        $storeInfo['store_name'] = $storeInfo['title'];
        $storeInfo['total'] = $storeInfo['sales'];
        $storeInfo['product_is_show'] = app()->make(StoreProductServices::class)->value($storeInfo['product_id'], 'is_show');

        if (sys_config('share_qrcode', 0) && request()->isWechat()) {
            /** @var QrcodeServices $qrcodeService */
            $qrcodeService = app()->make(QrcodeServices::class);
            $storeInfo['wechat_code'] = $qrcodeService->getTemporaryQrcode('seckill-' . $id, $uid)->url;
        } else {
            $storeInfo['wechat_code'] = '';
        }

        /** @var StoreOrderServices $storeOrderServices */
        $storeOrderServices = app()->make(StoreOrderServices::class);
        $data['buy_num'] = $storeOrderServices->getBuyCount($uid, 'seckill_id', $id);

        /** @var StoreProductRelationServices $storeProductRelationServices */
        $storeProductRelationServices = app()->make(StoreProductRelationServices::class);
        $storeInfo['userCollect'] = $storeProductRelationServices->isProductRelation(['uid' => $uid, 'product_id' => $storeInfo['product_id'], 'type' => 'collect', 'category' => 'product']);
        $storeInfo['userLike'] = false;
        $storeInfo['uid'] = $uid;

        if ($storeInfo['quota'] > 0) {
            $percent = (int)(($storeInfo['quota_show'] - $storeInfo['quota']) / $storeInfo['quota_show'] * 100);
            $storeInfo['percent'] = $percent;
            $storeInfo['stock'] = $storeInfo['quota'];
        } else {
            $storeInfo['percent'] = 100;
            $storeInfo['stock'] = 0;
        }

        //获取秒杀商品状态
        if ($storeInfo['status'] == 1) {
            if ($storeInfo['start_time'] > time()) {
                $storeInfo['status'] = 2;
            } elseif (($storeInfo['stop_time'] + 86400) < time()) {
                $storeInfo['status'] = 0;
            } else {
                /** @var SystemGroupDataServices $systemGroupDataService */
                $systemGroupDataService = app()->make(SystemGroupDataServices::class);
                $seckillTime = array_column($systemGroupDataService->getConfigNameValue('routine_seckill_time'), null, 'id');
                $config = $seckillTime[$time_id] ?? false;
                if (!$config) {
                    throw new ApiException(410322);
                }
                $now_hour = date('H', time());
                $start_hour = $config['time'];
                $end_hour = (int)$start_hour + (int)$config['continued'];
                if ($start_hour <= $now_hour && $end_hour > $now_hour) {
                    $storeInfo['status'] = 1;
                } else if ($start_hour > $now_hour) {
                    $storeInfo['status'] = 2;
                } else {
                    $storeInfo['status'] = 0;
                }
            }
        } else {
            $storeInfo['status'] = 0;
        }

        /** @var SystemGroupDataServices $groupDataService */
        $groupDataService = app()->make(SystemGroupDataServices::class);
        $timeInfo = json_decode($groupDataService->value(['id' => $time_id], 'value'), true);
        $today = strtotime(date('Y-m-d'));
        $activityEndHour = $timeInfo['time']['value'] + $timeInfo['continued']['value'];
        $storeInfo['last_time'] = (int)bcadd((string)$today, (string)bcmul((string)$activityEndHour, '3600', 0));

        //商品详情
        $data['storeInfo'] = get_thumb_water($storeInfo, 'big', ['image', 'images']);
        $storeInfoNew = get_thumb_water($storeInfo, 'small');
        $data['storeInfo']['small_image'] = $storeInfoNew['image'];

        /** @var StoreProductReplyServices $storeProductReplyService */
        $storeProductReplyService = app()->make(StoreProductReplyServices::class);
        $data['reply'] = get_thumb_water($storeProductReplyService->getRecProductReply($storeInfo['product_id']), 'small', ['pics']);
        [$replyCount, $goodReply, $replyChance] = $storeProductReplyService->getProductReplyData((int)$storeInfo['product_id']);
        $data['replyChance'] = $replyChance;
        $data['replyCount'] = $replyCount;

        /** @var StoreProductAttrServices $storeProductAttrServices */
        $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
        list($productAttr, $productValue) = $storeProductAttrServices->getProductAttrDetail($id, $uid, 0, 1, $storeInfo['product_id']);
        $data['productAttr'] = $productAttr;
        $data['productValue'] = $productValue;
        $data['routine_contact_type'] = sys_config('routine_contact_type', 0);

        //用户访问事件
        event('UserVisitListener', [$uid, $id, 'seckill', $storeInfo['product_id'], 'view']);
        //浏览记录
        ProductLogJob::dispatch(['visit', ['uid' => $uid, 'product_id' => $storeInfo['product_id']]]);
        return $data;
    }

    /**
     * 获取秒杀数据
     * @param array $ids
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getSeckillColumn(array $ids, string $field = '')
    {
        $seckillProduct = $systemGroupData = [];
        $seckillInfoField = 'id,image,price,ot_price,postage,give_integral,sales,stock,title as store_name,unit_name,is_show,is_del,is_postage,cost,temp_id,weight,volume,start_time,stop_time,time_id';
        if (!empty($seckill_ids)) {
            $seckillProduct = $this->dao->idSeckillList($ids, $field ?: $seckillInfoField);
            if (!empty($seckillProduct)) {
                $timeIds = Arr::getUniqueKey($seckillProduct, 'time_id');
                $seckillProduct = array_combine(array_column($seckillProduct, 'id'), $seckillProduct);
                /** @var SystemGroupDataServices $groupServices */
                $groupServices = app()->make(SystemGroupDataServices::class);
                $systemGroupData = $groupServices->getGroupDataColumn($timeIds);
            }
        }
        return [$seckillProduct, $systemGroupData];
    }

    /**
     * 检查秒杀库存
     * @param int $uid
     * @param int $seckillId
     * @param int $cartNum
     * @param string $unique
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2023/03/01
     */
    public function checkSeckillStock(int $uid, int $seckillId, int $cartNum = 1, string $unique = '')
    {
        /** @var StoreProductAttrValueServices $attrValueServices */
        $attrValueServices = app()->make(StoreProductAttrValueServices::class);
        if ($unique == '') {
            $unique = $attrValueServices->value(['product_id' => $seckillId, 'type' => 1], 'unique');
        }
        //检查商品活动状态
        $StoreSeckillinfo = $this->getSeckillCount($seckillId, '*,title as store_name');
        if ($StoreSeckillinfo['once_num'] < $cartNum) {
            throw new ApiException(410313, ['num' => $StoreSeckillinfo['once_num']]);
        }
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $userBuyCount = $orderServices->getBuyCount($uid, 'seckill_id', $seckillId);
        if ($StoreSeckillinfo['num'] < ($userBuyCount + $cartNum)) {
            throw new ApiException(410298, ['num' => $StoreSeckillinfo['num']]);
        }
        if ($StoreSeckillinfo['num'] < $cartNum) {
            throw new ApiException(410317, ['num' => $StoreSeckillinfo['num']]);
        }
        $attrInfo = $attrValueServices->getOne(['product_id' => $seckillId, 'unique' => $unique, 'type' => 1]);
        if (!$attrInfo || $attrInfo['product_id'] != $seckillId) {
            throw new ApiException(410305);
        }
        if ($cartNum > $attrInfo['quota']) {
            throw new ApiException(410296);
        }
        return [$attrInfo, $unique, $StoreSeckillinfo];
    }

    /**
     * 修改秒杀库存
     * @param int $num
     * @param int $seckillId
     * @param string $unique
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2023/03/01
     */
    public function decSeckillStock(int $num, int $seckillId, string $unique = '')
    {
        $product_id = $this->dao->value(['id' => $seckillId], 'product_id');
        if ($unique) {
            /** @var StoreProductAttrValueServices $skuValueServices */
            $skuValueServices = app()->make(StoreProductAttrValueServices::class);
            //减去秒杀商品的sku库存增加销量
            $res = false !== $skuValueServices->decProductAttrStock($seckillId, $unique, $num, 1);
            //减去秒杀库存
            $res = $res && $this->dao->decStockIncSales(['id' => $seckillId, 'type' => 1], $num);
            //减去当前普通商品sku的库存增加销量
            $suk = $skuValueServices->value(['unique' => $unique, 'product_id' => $seckillId, 'type' => 1], 'suk');
            $productUnique = $skuValueServices->value(['suk' => $suk, 'product_id' => $product_id, 'type' => 0], 'unique');
            if ($productUnique) {
                $res = $res && $skuValueServices->decProductAttrStock($product_id, $productUnique, $num);
            }
        } else {
            $res = false !== $this->dao->decStockIncSales(['id' => $seckillId, 'type' => 1], $num);
        }
        /** @var StoreProductServices $services */
        $services = app()->make(StoreProductServices::class);
        //减去普通商品库存
        return $res && $services->decProductStock($num, $product_id);

    }

    /**
     * 加库存减销量
     * @param int $num
     * @param int $seckillId
     * @param string $unique
     * @return bool
     */
    public function incSeckillStock(int $num, int $seckillId, string $unique = '')
    {
        $product_id = $this->dao->value(['id' => $seckillId], 'product_id');
        if ($unique) {
            /** @var StoreProductAttrValueServices $skuValueServices */
            $skuValueServices = app()->make(StoreProductAttrValueServices::class);
            //减去秒杀商品的sku库存增加销量
            $res = false !== $skuValueServices->incProductAttrStock($seckillId, $unique, $num, 1);
            //减去秒杀库存
            $res = $res && $this->dao->incStockDecSales(['id' => $seckillId, 'type' => 1], $num);
            //减去当前普通商品sku的库存增加销量
            $suk = $skuValueServices->value(['unique' => $unique, 'product_id' => $seckillId], 'suk');
            $productUnique = $skuValueServices->value(['suk' => $suk, 'product_id' => $product_id], 'unique');
            if ($productUnique) {
                $res = $res && $skuValueServices->incProductAttrStock($product_id, $productUnique, $num);
            }
        } else {
            $res = false !== $this->dao->incStockDecSales(['id' => $seckillId, 'type' => 1], $num);
        }
        /** @var StoreProductServices $services */
        $services = app()->make(StoreProductServices::class);
        //减去普通商品库存
        $res = $res && $services->incProductStock($num, $product_id);
        return $res;
    }

    /**
     * 获取一条秒杀商品
     * @param $id
     * @param string $field
     * @return array|false|\PDOStatement|string|\think\Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getValidProduct($id, $field = '*')
    {
        return $this->dao->validProduct($id, $field);
    }

    /**
     * 秒杀统计
     * @param $id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function seckillStatistics($id)
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $pay_count = $orderServices->getDistinctCount([['seckill_id', '=', $id], ['pid', '<>', -1], ['paid', '=', 1], ['refund_type', 'in', [0, 3]]], 'uid', false);
        $order_count = $orderServices->getDistinctCount([['seckill_id', '=', $id], ['pid', '<>', -1], ['refund_type', 'in', [0, 3]]], 'uid', false);
        $all_price = $orderServices->sum([['seckill_id', '=', $id], ['pid', '<>', -1], ['refund_type', 'in', [0, 3]], ['paid', '=', 1]], 'pay_price');
        $seckillInfo = $this->dao->get($id);
        $pay_rate = $seckillInfo['quota'] . '/' . $seckillInfo['quota_show'];
        return compact('pay_count', 'order_count', 'all_price', 'pay_rate');
    }

    /**
     * 秒杀参与人统计
     * @param $id
     * @param string $keyword
     * @return array
     */
    public function seckillPeople($id, $keyword = '')
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        [$page, $limit] = $this->getPageValue();
        $list = $orderServices->seckillPeople($id, $keyword, $page, $limit);
        $count = $orderServices->getDistinctCount([['seckill_id', '=', $id], ['pid', '<>', -1], ['real_name|uid|user_phone', 'like', '%' . $keyword . '%']], 'uid', false);
        foreach ($list as &$item) {
            $item['add_time'] = date('Y-m-d H:i:s', $item['add_time']);
        }
        return compact('list', 'count');
    }

    /**
     * 秒杀订单统计
     * @param $id
     * @param array $where
     * @return array
     */
    public function seckillOrder($id, $where = [])
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        [$page, $limit] = $this->getPageValue();
        $where = $where + ['paid' => 1, 'refund_status' => 0, 'is_del' => 0, 'pid' => 0];
        $list = $orderServices->seckillOrder($id, $where, $page, $limit);
        $count = $orderServices->seckillCount($id, $where);
        foreach ($list as &$item) {
            if ($item['status'] == 0) {
                if ($item['paid'] == 0) {
                    $item['status'] = '未支付';
                } else {
                    $item['status'] = '未发货';
                }
            } elseif ($item['status'] == 1) {
                $item['status'] = '待收货';
            } elseif ($item['status'] == 2) {
                $item['status'] = '待评价';
            } elseif ($item['status'] == 3) {
                $item['status'] = '已完成';
            } elseif ($item['status'] == -2) {
                $item['status'] = '已退款';
            } else {
                $item['status'] = '未知';
            }
            $item['add_time'] = date('Y-m-d H:i:s', $item['add_time']);
            $item['pay_time'] = $item['pay_time'] ? date('Y-m-d H:i:s', $item['pay_time']) : '';
        }
        return compact('list', 'count');
    }

    public function seckillActivitySave($id, $data)
    {
        if ($data['section_time']) {
            [$start_time, $end_time] = $data['section_time'];
            if (strtotime($end_time) + 86400 < time()) {
                throw new AdminException('活动结束时间不能小于当前时间');
            }
        }
        if ($data['num'] < $data['once_num']) {
            throw new AdminException('限制单次购买数量不能大于总购买数量');
        }

        $data['start_day'] = strtotime($data['section_time'][0]);
        $data['end_day'] = strtotime($data['section_time'][1]);
        $data['type'] = 1;
        $timeIds = $data['time_ids'];
        $data['time_ids'] = implode(',', $data['time_ids']);

        return $this->transaction(function () use ($id, $data, $timeIds) {

            $productInfos = $data['product_infos'];
            $productIds = array_column($productInfos, 'id');
            /** @var StoreProductServices $productServices */
            $productServices = app()->make(StoreProductServices::class);
            $productList = $productServices->searchList(['id' => $productIds, 'is_del' => 0]);
            $productList = $productList['list'] ?? [];
            $productInfos = array_combine($productIds, $productInfos);

            /** @var StoreActivityServices $StoreActivityServices */
            $StoreActivityServices = app()->make(StoreActivityServices::class);
            if ($id) {
                $StoreActivityServices->update($id, $data);
                $this->clearActivitySeckill($id, $productIds);
            } else {
                $data['add_time'] = time();
                $res = $StoreActivityServices->save($data);
                $id = (int)$res->id;
            }
            foreach ($productList as &$product) {
                $attrInfo = $productServices->getProductRules((int)$product['id']);
                $seckillData = [];
                $seckillData['copy'] = 0;
                $seckillData['activity_id'] = $id;
                $seckillData['product_id'] = $product['id'] ?? 0;
                $seckillData['title'] = $product['store_name'] ?? '';
                $seckillData['info'] = $product['store_info'] ?? '';
                $seckillData['unit_name'] = $product['unit_name'] ?? '';
                $seckillData['section_time'] = $data['section_time'];
                $seckillData['images'] = $product['slider_image'] ?? '';
                $seckillData['description'] = $product['description'] ?? '';
                $seckillData['status'] = $productInfos[$product['id']]['status'] ?? 1;
                $seckillData['time_id'] = $timeIds;
                $seckillData['num'] = $data['num'] ?? 0;
                $seckillData['once_num'] = $data['once_num'] ?? 0;
                $seckillData['temp_id'] = $product['temp_id'];//运费设置
                $seckillData['freight'] = $product['freight'];//运费设置
                $seckillData['logistics'] = $product['logistics'];//运费设置
                $seckillData['postage'] = $product['postage'];//邮费
                $seckillData['custom_form'] = $product['custom_form'];//自定义表单
                $seckillData['virtual_type'] = $product['virtual_type'];//商品类型
                $seckillData['is_commission'] = $data['is_commission'];//是否返佣
                $seckillData['items'] = $attrInfo['items'];
                $attrs = $attrInfo['attrs'] ?? [];
                if ($attrs) {
                    $seckillAttrValue = $productInfos[$product['id']]['attrs'] ?? [];
                    if (!$seckillAttrValue) {
                        throw new AdminException('请选择商品规格');
                    }
                    foreach ($seckillAttrValue as $sattr) {
                        if (!isset($sattr['status']) || !$sattr['status']) {//不参与的规格不验证
                            continue;
                        }
                        if (!isset($sattr['price']) || !$sattr['price']) {
                            throw new AdminException('请填写商品（' . $product['store_name'] . ' | ' . $sattr['suk'] . '）活动价');
                        }
                        if ($sattr['price'] > $sattr['ot_price']) {
                            throw new AdminException('商品（' . $product['store_name'] . ' | ' . $sattr['suk'] . '）活动价不能大于原价');
                        }
                        if (!isset($sattr['quota']) || !$sattr['quota']) {
                            throw new AdminException('请填写商品（' . $product['store_name'] . ' | ' . $sattr['suk'] . '）限量');
                        }
                    }
                    $seckillAttrValue = array_combine(array_column($seckillAttrValue, 'suk'), $seckillAttrValue);
                    foreach ($attrs as $attr) {
                        $sku = implode(',', $attr['detail']);
                        if (!isset($seckillAttrValue[$sku])) {
                            throw new AdminException('请重新选择商品规格');
                        }
                        if (!isset($seckillAttrValue[$sku]['status']) || !$seckillAttrValue[$sku]['status']) {
                            continue;
                        }
                        if (($seckillAttrValue[$sku]['quota'] ?? 0) > $attr['stock']) {
                            throw new AdminException('限量超过了商品库存');
                        }
                        $attr['quota'] = $attr['quota_show'] = $seckillAttrValue[$sku]['quota'] ?? 0;
                        $attr['price'] = $seckillAttrValue[$sku]['price'] ?? 0;
                        $attr['cost'] = $seckillAttrValue[$sku]['cost'] ?? 0;
                        $attr['ot_price'] = $seckillAttrValue[$sku]['ot_price'] ?? 0;
                        $seckillData['attrs'][] = $attr;
                    }
                }
                $seckillId = $this->dao->value(['activity_id' => $id, 'product_id' => $seckillData['product_id']], 'id') ?? 0;
                $this->saveData($seckillId, $seckillData);
            }
            return true;
        });
    }

    public function clearActivitySeckill($id, $productIds = [])
    {
        $seckill = $this->dao->getList(['activity_id' => $id, 'is_del' => 0]);
        $seckillIds = [];
        foreach ($seckill as $item) {
            if (!in_array($item['product_id'], $productIds)) {
                $seckillIds[] = $item['id'];
            }
        }
        if (count($seckillIds)) {
            /** @var StoreProductAttrResultServices $storeProductAttrResultServices */
            $storeProductAttrResultServices = app()->make(StoreProductAttrResultServices::class);
            /** @var StoreDescriptionServices $storeDescriptionServices */
            $storeDescriptionServices = app()->make(StoreDescriptionServices::class);
            /** @var StoreProductAttrServices $storeProductAttrServices */
            $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
            /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
            $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
            $where = ['product_id' => $seckillIds, 'type' => 1];
            $storeProductAttrResultServices->delete($where);
            $storeDescriptionServices->delete($where);
            $storeProductAttrServices->delete($where);
            $storeProductAttrValueServices->delete($where);
            $this->dao->delete(['activity_id' => $id, 'is_del' => 0]);
        }
        return true;
    }
}
