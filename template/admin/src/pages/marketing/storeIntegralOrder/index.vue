<template>
  <div v-loading="spinShow">
    <productlist-details
      class="ivu-mt"
      v-if="currentTab === 'article' || 'project' || 'app'"
      ref="productlist"
    ></productlist-details>
  </div>
</template>

<script>
import productlistDetails from './orderlistDetails';
import { mapMutations } from 'vuex';
export default {
  name: 'list',
  components: {
    productlistDetails,
  },
  data() {
    return {
      spinShow: false,
      currentTab: '',
      data: [],
      tablists: null,
    };
  },
  created() {
    this.getOrderType('');
    this.getOrderStatus('');
    this.getOrderTime('');
    this.getOrderNum('');
    this.getfieldKey('');
    this.onChangeTabs('');
  },
  beforeDestroy() {
    this.getOrderType('');
    this.getOrderStatus('');
    this.getOrderTime('');
    this.getOrderNum('');
    this.getfieldKey('');
    this.onChangeTabs('');
  },
  mounted() {
    this.getTabs();
  },
  methods: {
    ...mapMutations('integralOrder', [
      'onChangeTabs',
      'getOrderStatus',
      'getOrderTime',
      'getOrderNum',
      'getfieldKey',
      'getOrderType',
      // 'onChangeChart'
    ]),
    // 订单类型  @on-changeTabs="getChangeTabs"
    getTabs() {
      this.spinShow = true;
      this.$store
        .dispatch('integralOrder/getOrderTabs', {
          data: '',
        })
        .then((res) => {
          this.tablists = res.data;
          // this.onChangeChart(this.tablists)
          this.spinShow = false;
        })
        .catch((res) => {
          this.spinShow = false;
          this.$message.error(res.msg);
        });
      // getOrdes({}).then(async res => {
      //     this.tablists = res.data;
      //     this.onChangeChart(this.tablists)
      //     this.spinShow = false;
      // }).catch(res => {
      //     this.spinShow = false;
      //     this.$message.error(res.msg);
      // })
    },
    onClickTab() {
      this.onChangeTabs(Number(this.currentTab));
      this.$store.dispatch('integralOrder/getOrderTabs', {
        data: '',
        type: Number(this.currentTab),
      });
      this.$refs.productlist.getChangeTabs();
    },
  },
};
</script>
<style lang="scss" scoped>
.product_tabs ::v-deep .ivu-tabs-bar {
  margin-bottom: 0px !important;
}
.product_tabs ::v-deep .ivu-page-header-content {
  margin-bottom: 0px !important;
}
.product_tabs ::v-deep .ivu-page-header-breadcrumb {
  margin-bottom: 0px !important;
}
</style>
