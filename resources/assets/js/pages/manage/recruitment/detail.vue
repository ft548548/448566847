<template>
    <div class="page-recruitment-detail">
        <PageTitle :title="$L('项目大厅')"/>
        <Spin v-if="loading" fix/>
        <div v-if="detail" class="recruitment-detail-body overlay-y">
            <!-- 基本信息 -->
            <div class="detail-head">
                <div class="detail-title">{{detail.title}}</div>
                <span :class="['recruitment-status', 'status-' + detail.status]">{{statusText(detail.status)}}</span>
            </div>
            <div class="detail-desc">{{detail.description}}</div>
            <div class="detail-tags" v-if="detail.skills && detail.skills.length > 0">
                <Tag v-for="(skill, i) in detail.skills" :key="i">{{skill}}</Tag>
            </div>
            <div class="detail-meta">
                <span>{{$L('队伍')}} {{detail.member_count}}/{{detail.headcount}}</span>
                <span v-if="detail.deadline">{{$L('截止')}} {{detail.deadline}}</span>
                <span v-if="detail.publisher_nickname">{{$L('发布者')}} {{detail.publisher_nickname}}</span>
                <span v-if="detail.source_project" class="detail-source">
                    <Icon type="md-folder-open" size="14"/>
                    {{$L('来源项目')}}：{{detail.source_project.name}}
                    <template v-if="detail.source_project.owner_nickname">（{{detail.source_project.owner_nickname}}）</template>
                    <Button
                        v-if="detail.source_project.is_source_member"
                        type="text"
                        size="small"
                        icon="md-arrow-forward"
                        @click="goSourceProject">{{$L('去项目')}}</Button>
                </span>
            </div>

            <!-- 队伍成员 -->
            <div class="detail-section">
                <div class="section-title">{{$L('队伍成员')}} ({{members.length}})</div>
                <div v-if="members.length === 0" class="section-empty">{{$L('暂无成员')}}</div>
                <div v-for="m in members" :key="m.id" class="member-row">
                    <UserAvatar :userid="m.userid" :size="28"/>
                    <span class="member-name">{{m.nickname}}</span>
                    <span v-if="m.userid === detail.publisher_id" class="member-owner">{{$L('发布者')}}</span>
                    <Button
                        v-if="isPublisher && m.userid !== detail.publisher_id"
                        size="small"
                        type="error"
                        ghost
                        @click="removeMember(m)">{{$L('移除')}}</Button>
                </div>
            </div>

            <!-- 报名区 -->
            <div v-if="detail.applications" class="detail-section">
                <div class="section-title">{{$L('报名列表')}}</div>
                <div v-if="detail.applications.length === 0" class="section-empty">{{$L('暂无报名')}}</div>
                <div v-for="a in detail.applications" :key="a.id" class="apply-row">
                    <UserAvatar :userid="a.applicant_id" :size="28"/>
                    <span class="member-name">{{a.nickname}}</span>
                    <span class="apply-msg">{{a.message}}</span>
                    <Tag v-if="a.status === 'pending'" color="orange">{{$L('待审批')}}</Tag>
                    <Tag v-else-if="a.status === 'approved'" color="green">{{$L('已通过')}}</Tag>
                    <Tag v-else-if="a.status === 'rejected'" color="red">{{$L('已拒绝')}}</Tag>
                    <Tag v-else>{{$L('已撤回')}}</Tag>
                    <template v-if="isPublisher && a.status === 'pending'">
                        <Button size="small" type="success" @click="reviewApply(a, 'approve')">{{$L('通过')}}</Button>
                        <Button size="small" type="error" ghost @click="reviewApply(a, 'reject')">{{$L('拒绝')}}</Button>
                    </template>
                </div>
            </div>

            <!-- 操作区 -->
            <div class="detail-actions">
                <!-- 来源项目入口：仅来源项目成员可见，跳转到来源项目，可进一步通过邀请链接加入项目群聊 -->
                <Button
                    v-if="detail.source_project && detail.source_project.is_source_member"
                    type="success"
                    icon="md-chatbubbles"
                    @click="goSourceProject">{{$L('进入项目群聊')}}</Button>

                <!-- 报名按钮（非发布者，且未入队） -->
                <Button
                    v-if="!isPublisher && detail.status === 'recruiting' && !detail.is_member && detail.my_application === null"
                    type="primary"
                    @click="showApply">{{$L('报名加入')}}</Button>
                <Tag v-else-if="detail.is_member" color="green">{{$L('你已在队伍中')}}</Tag>
                <Tag v-else-if="detail.my_application === 'pending'" color="orange">{{$L('报名待审批')}}</Tag>

                <!-- 发布者管理 -->
                <template v-if="isPublisher">
                    <Button
                        v-if="detail.status === 'recruiting' || detail.status === 'full'"
                        type="primary"
                        @click="showConvert">{{$L('成队转正')}}</Button>
                    <Button
                        v-if="detail.status === 'recruiting' || detail.status === 'full'"
                        @click="changeStatus('start')">{{$L('开始进行')}}</Button>
                    <Button
                        v-if="detail.status === 'in_progress'"
                        @click="changeStatus('complete')">{{$L('标记完成')}}</Button>
                    <Button
                        v-if="detail.status !== 'closed' && detail.status !== 'completed'"
                        type="error"
                        ghost
                        @click="changeStatus('close')">{{$L('关闭招募')}}</Button>
                    <Button
                        type="error"
                        @click="showRemoveConfirm">{{$L('删除招募')}}</Button>
                </template>
            </div>
        </div>

        <!-- 报名弹窗 -->
        <Modal v-model="applyShow" :title="$L('报名加入')" :mask-closable="false">
            <Form :label-width="80">
                <FormItem :label="$L('报名留言')">
                    <Input v-model="applyMessage" type="textarea" :rows="3"/>
                </FormItem>
            </Form>
            <div slot="footer">
                <Button @click="applyShow = false">{{$L('取消')}}</Button>
                <Button type="primary" :loading="submitting" @click="submitApply">{{$L('提交')}}</Button>
            </div>
        </Modal>

        <!-- 转正弹窗 -->
        <Modal v-model="convertShow" :title="$L('成队转正')" :mask-closable="false">
            <Form :label-width="80">
                <FormItem :label="$L('项目名称')">
                    <Input v-model="convertName" type="text" :placeholder="$L('转正后新建的项目名称')"/>
                </FormItem>
            </Form>
            <div slot="footer">
                <Button @click="convertShow = false">{{$L('取消')}}</Button>
                <Button type="primary" :loading="submitting" @click="submitConvert">{{$L('确认转正')}}</Button>
            </div>
        </Modal>

        <!-- 删除确认弹窗 -->
        <Modal v-model="removeShow" :title="$L('删除招募')" :mask-closable="false">
            <p style="font-size:14px;line-height:1.8;color:#333;">
                {{$L('确定要删除该招募吗？删除后相关报名与成员记录将一并清除，且不可恢复。')}}
            </p>
            <div slot="footer">
                <Button @click="removeShow = false">{{$L('取消')}}</Button>
                <Button type="error" :loading="removing" @click="submitRemove">{{$L('确认删除')}}</Button>
            </div>
        </Modal>
    </div>
</template>

<script>
import {mapState} from "vuex";
import UserAvatar from "../../../components/UserAvatar";

export default {
    name: 'RecruitmentDetail',
    components: {UserAvatar},
    data() {
        return {
            loading: false,
            submitting: false,
            detail: null,
            members: [],

            applyShow: false,
            applyMessage: '',

            convertShow: false,
            convertName: '',

            removeShow: false,
            removing: false,
        }
    },

    computed: {
        ...mapState(['userId']),
        isPublisher() {
            return this.detail && this.detail.is_publisher === true;
        },
    },

    activated() {
        this.getDetail();
    },

    mounted() {
        // 整页刷新/换号后从 login 整页跳回的场景下，keep-alive 不触发 activated，
        // 必须在此兜底加载，否则详情空白、数据残留。
        this.getDetail();
    },

    methods: {
        statusText(status) {
            const map = {
                recruiting: '招募中',
                full: '已满员',
                in_progress: '进行中',
                completed: '已完成',
                closed: '已关闭',
            };
            return this.$L(map[status] || status);
        },

        getDetail() {
            this.loading = true;
            // 加载前清空旧数据，避免换号后残留上一账号的详情
            this.detail = null;
            this.members = [];
            this.$store.dispatch('call', {
                url: 'recruitment/one',
                data: {recruitment_id: this.$route.params.id},
            }).then(({data}) => {
                this.detail = data;
                this.members = data.members || [];
                this.convertName = data.title || '';
            }).catch((err) => {
                this.detail = null;
                this.members = [];
                $A.modalError({content: err.msg});
            }).finally(() => {
                this.loading = false;
            });
        },

        showApply() {
            this.applyMessage = '';
            this.applyShow = true;
        },

        // 跳转到来源项目页（进入后可进一步通过邀请链接加入项目群聊）
        goSourceProject() {
            if (!this.detail || !this.detail.source_project || !this.detail.source_project.id) {
                $A.modalWarning({content: this.$L('该项目不存在或已被删除')});
                return;
            }
            this.goForward({path: '/manage/project/' + this.detail.source_project.id});
        },

        submitApply() {
            this.submitting = true;
            this.$store.dispatch('call', {
                url: 'recruitment/apply',
                data: {recruitment_id: this.detail.id, message: this.applyMessage},
            }).then(() => {
                $A.modalSuccess({content: this.$L('报名成功')});
                this.applyShow = false;
                this.getDetail();
            }).catch((err) => {
                $A.modalError({content: err.msg});
            }).finally(() => {
                this.submitting = false;
            });
        },

        reviewApply(application, result) {
            this.$store.dispatch('call', {
                url: 'recruitment/review/apply',
                data: {
                    recruitment_id: this.detail.id,
                    application_id: application.id,
                    result,
                },
            }).then(() => {
                this.getDetail();
            }).catch((err) => {
                $A.modalError({content: err.msg});
            });
        },

        removeMember(member) {
            this.$store.dispatch('call', {
                url: 'recruitment/member/remove',
                data: {recruitment_id: this.detail.id, member_id: member.id},
            }).then(() => {
                this.getDetail();
            }).catch((err) => {
                $A.modalError({content: err.msg});
            });
        },

        changeStatus(action) {
            this.$store.dispatch('call', {
                url: 'recruitment/status',
                data: {recruitment_id: this.detail.id, action},
            }).then(() => {
                this.getDetail();
            }).catch((err) => {
                $A.modalError({content: err.msg});
            });
        },

        showConvert() {
            this.convertShow = true;
        },

        submitConvert() {
            this.submitting = true;
            this.$store.dispatch('call', {
                url: 'recruitment/convert',
                data: {recruitment_id: this.detail.id, name: this.convertName},
            }).then(({data}) => {
                $A.modalSuccess({content: this.$L('转正成功，项目ID：' + data.project_id)});
                this.convertShow = false;
                this.getDetail();
            }).catch((err) => {
                $A.modalError({content: err.msg});
            }).finally(() => {
                this.submitting = false;
            });
        },

        showRemoveConfirm() {
            this.removeShow = true;
        },

        submitRemove() {
            this.removing = true;
            this.$store.dispatch('call', {
                url: 'recruitment/remove',
                data: {recruitment_id: this.detail.id},
            }).then(() => {
                $A.modalSuccess({content: this.$L('删除成功')});
                this.removeShow = false;
                // 删除后返回列表页，列表页 activated/mounted 会重新拉取，实现实时更新
                this.$router.replace({name: 'manage-recruitment'});
            }).catch((err) => {
                $A.modalError({content: err.msg});
            }).finally(() => {
                this.removing = false;
            });
        },
    },
}
</script>

<style scoped>
.page-recruitment-detail {
    height: 100%;
    padding: 20px;
    position: relative;
}
.recruitment-detail-body {
    height: calc(100% - 30px);
    overflow: auto;
}
.detail-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.detail-title {
    font-size: 20px;
    font-weight: 600;
    color: #333;
}
.recruitment-status {
    font-size: 12px;
    padding: 2px 10px;
    border-radius: 12px;
}
.status-recruiting {background: #e6f7ff; color: #1890ff;}
.status-full {background: #fff7e6; color: #fa8c16;}
.status-in_progress {background: #f6ffed; color: #52c41a;}
.status-completed {background: #f9f0ff; color: #722ed1;}
.status-closed {background: #f5f5f5; color: #999;}
.detail-desc {
    color: #666;
    margin-bottom: 12px;
    white-space: pre-wrap;
}
.detail-tags {margin-bottom: 12px;}
.detail-meta {
    display: flex;
    gap: 20px;
    color: #999;
    font-size: 13px;
    margin-bottom: 20px;
}
.detail-source {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: #1890ff;
}
.detail-section {
    margin-bottom: 24px;
}
.section-title {
    font-size: 15px;
    font-weight: 600;
    color: #333;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid #f0f0f0;
}
.section-empty {
    color: #999;
    text-align: center;
    padding: 16px 0;
}
.member-row, .apply-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 0;
}
.member-name {
    font-weight: 500;
    color: #333;
}
.member-owner {
    font-size: 12px;
    color: #1890ff;
}
.apply-msg {
    flex: 1;
    color: #666;
    font-size: 13px;
}
.detail-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    padding-top: 16px;
    border-top: 1px solid #f0f0f0;
}
</style>
