<template>
  <div class="txt_tab" v-if="datas[name]">
    <div class="c_row-item">
      <el-col class="c_label">
        {{ datas[name].title }}
        <span>{{ datas[name].list[datas[name].type].val }}</span>
      </el-col>
      <el-col class="color-box">
        <el-radio-group v-model="datas[name].type" size="mini" type="button" @input="radioChange($event)">
          <el-radio-button :label="key" v-for="(radio, key) in datas[name].list" :key="key">
            <span class="iconfont" :class="radio.icon" v-if="radio.icon"></span>
            <span v-else>{{ radio.val }}</span>
          </el-radio-button>
        </el-radio-group>
      </el-col>
    </div>
  </div>
</template>

<script>
export default {
  name: 'c_txt_tab',
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
  data() {
    return {
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
      immediate: true,
      deep: true,
    },
  },
  methods: {
    radioChange(e) {
      this.$emit('getConfig', { name: 'radio', values: e });
    },
  },
};
</script>

<style lang="scss" scoped>
.txt_tab {
  margin-top: 20px;
}
.c_row-item {
  margin-bottom: 20px;
}
.row-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.iconfont {
  font-size: 18px;
}
</style>
