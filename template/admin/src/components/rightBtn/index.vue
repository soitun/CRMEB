<template>
  <div class="btn-box" style="display: none">
    <el-button type="primary" v-db-click @click="handleSubmit('formInline')" style="text-align: center; width: 60%"
      >确定</el-button
    >
  </div>
</template>

<script>
import { mapState, mapMutations, mapActions } from 'vuex';
export default {
  name: 'rightBtn',
  props: ['activeIndex', 'configObj'],
  methods: {
    // 右侧确认保存配置
    handleSubmit(name) {
      let obj = {};
      obj.activeIndex = this.activeIndex;
      obj.data = this.configObj;
      this.add(obj);
    },
    ...mapMutations({
      add: 'mobildConfig/UPDATEARR',
    }),
  },
};
</script>

<style scoped></style>
