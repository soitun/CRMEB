<template>
  <div class="line-box">
    <div class="title">
      <p>搜索热词</p>
      <span>{{ datas[name].title }}</span>
    </div>
    <div class="input-box">
      <draggable class="dragArea list-group" :list="datas[name].list" group="peoples" handle=".icon">
        <div class="input-item" v-for="(item, index) in datas[name].list" :key="index">
          <div class="icon">
            <i class="el-icon-s-grid" style="font-size: 20px"></i>
          </div>
          <el-input v-model="item.val" placeholder="选填，不超过十个字" :maxlength="item.maxlength || 10" />
          <div class="close" v-db-click @click="close(index)">
            <i class="el-icon-close" style="color: #d8d8d8; font-size: 20px"></i>
          </div>
        </div>
      </draggable>
      <div class="add-btn" v-db-click @click="addHotTxt">
        <el-button
          type="primary"
          ghost
          style="width: 100%; height: 40px; border-color: var(--prev-color-primary); color: var(--prev-color-primary)"
          >添加热词</el-button
        >
      </div>
    </div>
  </div>
</template>
<script>
import vuedraggable from 'vuedraggable';
export default {
  name: 'c_hot_word',
  props: {
    name: {
      type: String,
    },
    configData: {
      type: null,
    },
    configNum: {
      type: Number | String,
      default: 'default',
    },
  },
  components: {
    draggable: vuedraggable,
  },
  data() {
    return {
      hotWordList: [],
      hotIndex: 1,
      defaults: {},
      datas: this.configData[this.configNum],
    };
  },
  mounted() {},
  watch: {
    configData: {
      handler(nVal, oVal) {
        this.datas = nVal[this.configNum];
      },
      deep: true,
    },
  },
  methods: {
    addHotTxt() {
      let obj = {
        val: '',
      };
      if (this.datas[this.name].list.length < 20) {
        this.datas[this.name].list.push(obj);
      } else {
        this.$message.warning('最多添加20个热词');
      }
    },
    close(index) {
      this.datas[this.name].list.splice(index, 1);
    },
  },
};
</script>

<style lang="scss" scoped>
.line-box {
  margin-top: 20px;
  padding: 10px 0 20px;
  border-top: 1px solid rgba(0, 0, 0, 0.05);
  border-bottom: 1px solid rgba(0, 0, 0, 0.05);
  .title {
    p {
      font-size: 14px;
      color: #000000;
    }

    span {
      color: #999999;
    }
  }
  .input-box {
    position: relative;
    margin-top: 10px;
    .add-btn {
      margin-top: 18px;
    }
    .input-item {
      position: relative;
      display: flex;
      align-items: center;
      margin-bottom: 15px;
      .icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        cursor: move;
        color: #d8d8d8;
      }
      ::v-deep .ivu-input {
        flex: 1;
        height: 36px;
      }
    }
    .close {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      cursor: pointer;
    }
  }
}
</style>
