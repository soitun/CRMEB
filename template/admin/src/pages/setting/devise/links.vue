<template>
  <div class="right-box">
    <div class="link-item" v-for="(item, index) in list" :key="index">
      <div class="title">{{ item.name }}</div>
      <div class="txt"><span>地址：</span>{{ item.url }}</div>
      <div class="txt" v-if="item.parameter">
        <p><span>参数：</span></p>
        <span v-for="(val, key, index) in item.parameter" :key="index"
          >{{ key + '=' + val }}<i style="font-style: normal">&</i></span
        >
      </div>
      <div class="tips">
        例如：{{ item.example }}
        <!--<el-button size="small" style="margin-left: 10px" v-clipboard:copy="item.example"-->
        <!--v-clipboard:success="onCopy"-->
        <!--v-clipboard:error="onError">复制</el-button>-->
        <span class="copy copy-data" v-db-click @click="onCopy(item.example)">复制</span>
      </div>
    </div>
  </div>
</template>

<script>
import ClipboardJS from 'clipboard';
import { getUrl } from '@/api/diy';
export default {
  name: 'links',
  data() {
    return {
      list: [
        {
          name: '商城首页',
          url: '/pages/goods/order_list/index',
          parameter: [{}],
          example: '/pages/activity/bargain/index',
        },
        {
          name: '商城首页',
          url: '/pages/goods/order_list/index',
          parameter: [{}],
          example: '/pages/activity/bargain/index',
        },
      ],
    };
  },
  created() {
    getUrl().then((res) => {
      this.list = res.data.url;
    });
  },
  mounted: function () {
    this.$nextTick(function () {
      const clipboard = new ClipboardJS('.copy-data');
      clipboard.on('success', () => {
        this.$message.success('复制成功');
      });
    });
  },
  methods: {
    onCopy(copyData) {
      this.$copyText(copyData)
        .then((message) => {
          this.$message.success('复制成功');
        })
        .catch((err) => {
          this.$message.error('复制失败');
        });
    },
    // onError () {
    //     this.$message.error('复制成功');
    // }
  },
};
</script>

<style lang="scss" scoped>
.right-box {
  width: 400px;
  margin-left: 50px;
  border: 1px solid #ddd;
  border-radius: 4px;
  height: 700px;
  overflow-y: scroll;
  padding: 0 10px;
  &::-webkit-scrollbar {
    /*滚动条整体样式*/
    width: 4px; /*高宽分别对应横竖滚动条的尺寸*/
    height: 1px;
  }
  &::-webkit-scrollbar-thumb {
    /*滚动条里面小方块*/
    border-radius: 4px;
    box-shadow: inset 0 0 5px rgba(0, 0, 0, 0.2);
    background: #535353;
  }
  &::-webkit-scrollbar-track {
    /*滚动条里面轨道*/
    box-shadow: inset 0 0 5px #fff;
    border-radius: 4px;
    background: #fff;
  }
  .link-item {
    padding: 10px 0;
    border-bottom: 1px solid #f5f5f5;
    .title {
      font-size: 14px;
      color: #2d8cf0;
    }
    .txt {
      margin: 5px 0;
      font-size: 12px;
      span {
        color: #333;
      }
      p {
        display: inline-block;
        color: #19be6b;
        margin-right: 10px;
        span {
          color: #333;
        }
        &.red {
          color: #f00;
        }
      }
    }
    .tips {
      font-size: 12px;
      color: #999;
      .copy {
        padding: 3px 5px;
        border: 1px solid #cccccc;
        border-radius: 5px;
        color: #333;
        cursor: pointer;
        margin-left: 5px;
        &:hover {
          border-color: #2d8cf0;
          color: #2d8cf0;
        }
      }
    }
  }
}
</style>
