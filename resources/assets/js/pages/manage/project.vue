<template>
    <div class="page-project">
        <ProjectList/>
        <ProjectDialog v-if="projectParameter('chat')"/>
    </div>
</template>

<script>
import {mapState, mapGetters} from "vuex";
import ProjectList from "./components/ProjectList";
import ProjectDialog from "./components/ProjectDialog";
export default {
    components: {ProjectDialog, ProjectList},
    data() {
        return {
            project_id: 0,
            projectDataTimer: null,
        }
    },

    mounted() {
        this.project_id = $A.runNum(this.$route.params.id);
    },

    deactivated() {
        this.clearProjectDataTimer();
        this.$store.dispatch("forgetTaskCompleteTemp", true);
    },

    beforeDestroy() {
        this.clearProjectDataTimer();
    },

    computed: {
        ...mapState(['cacheProjects', 'wsOpenNum']),
        ...mapGetters(['projectParameter']),
    },

    watch: {
        '$route' ({params}) {
            this.project_id = $A.runNum(params.id);
        },

        project_id() {
            this.getProjectData();
        },

        wsOpenNum(num) {
            if (num <= 1) return
            this.wsOpenTimeout && clearTimeout(this.wsOpenTimeout)
            this.wsOpenTimeout = setTimeout(() => {
                if (this.$route.name == 'manage-project') {
                    this.getProjectData();
                }
            }, 5000)
        }
    },

    methods: {
        // 清理排队中的加载任务，避免离开项目页后仍然发起请求
        clearProjectDataTimer() {
            if (this.projectDataTimer) {
                clearTimeout(this.projectDataTimer);
                this.projectDataTimer = null;
            }
        },

        // 当前是否仍停留在该项目页
        // 注意：router-view 被 keep-alive 缓存，切到别的页面时本组件不会销毁，
        // 因此异步回调返回时必须显式校验路由，否则会在其他页面弹窗并跳转。
        isOnProjectPage(id) {
            if (this._isDestroyed || this._isBeingDestroyed) return false;
            if (!this.$route) return false;
            return this.$route.name === 'manage-project'
                && $A.runNum(this.$route.params.id) === $A.runNum(id);
        },

        getProjectData() {
            let id = this.project_id;
            this.clearProjectDataTimer();
            if (id <= 0) return;
            this.projectDataTimer = setTimeout(() => {
                this.projectDataTimer = null;
                // 排队期间用户可能已切到其他页面（如项目大厅）或点了别的项目，直接放弃
                if ($A.runNum(this.project_id) !== $A.runNum(id)) return;
                if (!this.isOnProjectPage(id)) return;
                this.$store.state.projectId = $A.runNum(id);
                this.$store.dispatch("getProjectOne", id).then(() => {
                    if (!this.isOnProjectPage(id)) return;
                    this.$store.dispatch("getColumns", id).catch(() => {});
                    this.$store.dispatch("getTaskForProject", id).catch(() => {})
                }).catch(({msg}) => {
                    // 请求返回时用户可能早已离开项目页，静默丢弃，不弹窗、不跳转
                    if (!this.isOnProjectPage(id)) return;
                    $A.modalWarning({
                        content: msg,
                        onOk: () => {
                            // 弹窗停留期间用户也可能已经切走，跳转前再校验一次
                            if (!this.isOnProjectPage(id)) return;
                            const failedId = $A.runNum(id);
                            const project = this.cacheProjects.find(({id}) => id && $A.runNum(id) !== failedId);
                            if (project) {
                                $A.goForward({path: '/manage/project/' + project.id});
                            } else {
                                $A.goForward({name: 'manage-dashboard'});
                            }
                        }
                    });
                });
                this.$store.dispatch("forgetTaskCompleteTemp", true);
            });
        }
    }
}
</script>
