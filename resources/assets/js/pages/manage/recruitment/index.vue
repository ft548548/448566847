<template>
    <div class="page-recruitment">
        <PageTitle :title="$L('项目大厅')"/>
        <div class="recruitment-toolbar">
            <Input
                v-model="keyword"
                prefix="ios-search"
                :placeholder="$L('搜索标题/简介')"
                clearable
                style="width: 220px"
                @on-enter="onSearch"
                @on-clear="onSearch"/>
            <Select
                v-model="status"
                :placeholder="$L('状态筛选')"
                clearable
                style="width: 140px"
                @on-change="onSearch">
                <Option value="recruiting">{{$L('招募中')}}</Option>
                <Option value="full">{{$L('已满员')}}</Option>
                <Option value="in_progress">{{$L('进行中')}}</Option>
                <Option value="completed">{{$L('已完成')}}</Option>
                <Option value="closed">{{$L('已关闭')}}</Option>
            </Select>
            <Button type="primary" icon="md-add" @click="showAdd">{{$L('发布招募')}}</Button>
        </div>

        <div class="recruitment-list overlay-y">
            <Spin v-if="loading" fix/>
            <div v-if="lists.length === 0 && !loading" class="recruitment-empty">{{$L('暂无招募')}}</div>
            <div
                v-for="item in lists"
                :key="item.id"
                class="recruitment-card"
                @click="openDetail(item)">
                <div class="recruitment-card-head">
                    <span class="recruitment-title">{{item.title}}</span>
                    <span :class="['recruitment-status', 'status-' + item.status]">{{statusText(item.status)}}</span>
                </div>
                <div class="recruitment-desc">{{item.description}}</div>
                <div class="recruitment-tags" v-if="item.skills && item.skills.length > 0">
                    <Tag v-for="(skill, i) in item.skills" :key="i" size="small">{{skill}}</Tag>
                </div>
                <div class="recruitment-meta">
                    <span>{{$L('已入队')}} {{item.member_count}}/{{item.headcount}}</span>
                    <span v-if="item.deadline">{{$L('截止')}} {{item.deadline}}</span>
                    <span v-if="item.publisher_nickname">{{$L('发布者')}} {{item.publisher_nickname}}</span>
                    <span v-if="item.source_project" class="recruitment-source-tag">
                        <Icon type="md-folder-open" size="12"/>
                        {{$L('来自项目')}}：{{item.source_project.name}}
                        <template v-if="item.source_project.owner_nickname">（{{item.source_project.owner_nickname}}）</template>
                    </span>
                </div>
            </div>
        </div>

        <div class="recruitment-page" v-if="total > pageSize">
            <Page
                :current="currentPage"
                :total="total"
                :page-size="pageSize"
                @on-change="onPageChange"/>
        </div>

        <!--发布招募（步骤一：关联项目；步骤二：招募信息）-->
        <Modal v-model="addShow" :title="$L('发布招募')" :mask-closable="false" :footer-hide="true" width="560">
            <Steps :current="addStep" class="recruitment-steps">
                <Step :title="$L('关联项目')"/>
                <Step :title="$L('招募信息')"/>
            </Steps>

            <!-- 步骤一：关联项目（新建 / 选已有，二者共享同一项目数据） -->
            <div v-show="addStep === 0" class="add-step-body">
                <Form :label-width="80">
                    <FormItem :label="$L('项目来源')">
                        <RadioGroup v-model="addData.project_mode">
                            <Radio label="create">{{$L('新建项目')}}</Radio>
                            <Radio label="existing">{{$L('选已有项目')}}</Radio>
                        </RadioGroup>
                    </FormItem>

                    <template v-if="addData.project_mode === 'create'">
                        <FormItem :label="$L('项目名称')" required>
                            <Input v-model="addData.name" type="text" :placeholder="$L('请输入项目名称（2~32字，同时作为招募标题）')" maxlength="32"/>
                        </FormItem>
                        <FormItem :label="$L('项目介绍')">
                            <Input v-model="addData.desc" type="textarea" :rows="3" :placeholder="$L('选填，同时作为招募简介')" maxlength="255"/>
                        </FormItem>
                    </template>

                    <template v-else>
                        <Spin v-if="projectLoading" fix/>
                        <FormItem :label="$L('选择项目')" required>
                            <Select
                                v-model="addData.project_id"
                                :placeholder="$L('输入关键词搜索并选择项目')"
                                filterable
                                :filter-method="filterProjects"
                                style="width: 100%">
                                <Option v-for="p in myProjects" :key="p.id" :value="p.id" :label="p.name">
                                    {{p.name}}
                                    <span v-if="p.owner == 1" class="project-owner-tag">{{$L('我负责')}}</span>
                                    <span v-else class="project-member-tag">{{$L('我参与')}}</span>
                                </Option>
                            </Select>
                        </FormItem>
                        <div v-if="myProjects.length === 0 && !projectLoading" class="add-empty-tip">{{$L('暂无可选项目，请切换到「新建项目」')}}</div>
                    </template>
                </Form>
            </div>

            <!-- 步骤二：招募信息（默认从项目带出，避免重复录入） -->
            <div v-show="addStep === 1" class="add-step-body">
                <Form ref="addForm" :model="addData" :rules="addRule" :label-width="80" @submit.native.prevent>
                    <FormItem prop="title" :label="$L('招募标题')">
                        <Input v-model="addData.title" type="text" maxlength="32"/>
                    </FormItem>
                    <FormItem prop="description" :label="$L('招募简介')">
                        <Input v-model="addData.description" type="textarea" :rows="3"/>
                    </FormItem>
                    <FormItem prop="skills" :label="$L('所需技能')">
                        <TagInput v-model="addData.skills"/>
                    </FormItem>
                    <FormItem prop="headcount" :label="$L('招募人数')">
                        <InputNumber v-model="addData.headcount" :min="1" :max="100"/>
                    </FormItem>
                    <FormItem prop="deadline" :label="$L('截止时间')">
                        <DatePicker v-model="addData.deadline" type="datetime" :placeholder="$L('选择截止时间')"/>
                    </FormItem>
                </Form>
            </div>

            <div class="add-footer">
                <Button v-if="addStep === 1" @click="addStep = 0">{{$L('上一步')}}</Button>
                <Button @click="addShow = false">{{$L('取消')}}</Button>
                <Button v-if="addStep === 0" type="primary" :loading="projectLoading" @click="nextToRecruit">{{$L('下一步')}}</Button>
                <Button v-else type="primary" :loading="submitting" @click="submitAdd">{{$L('发布')}}</Button>
            </div>
        </Modal>
    </div>
</template>

<script>
import {mapState} from "vuex";
import TagInput from "../../../components/TagInput";

export default {
    name: 'RecruitmentIndex',
    components: {TagInput},
    data() {
        return {
            loading: false,
            lists: [],
            keyword: '',
            status: '',
            currentPage: 1,
            pageSize: 20,
            total: 0,

            addShow: false,
            addStep: 0,
            submitting: false,
            projectLoading: false,
            myProjects: [],
            addData: {
                project_mode: 'create',
                name: '',
                desc: '',
                project_id: null,
                title: '',
                description: '',
                skills: '',
                headcount: 1,
                deadline: '',
            },
            addRule: {
                title: [{required: true, message: this.$L('请输入招募标题'), trigger: 'blur'}],
            },
        }
    },

    computed: {
        ...mapState(['userId']),
    },

    activated() {
        this.getLists();
    },

    mounted() {
        // 整页刷新/换号后从 login 整页跳回的场景下，keep-alive 不触发 activated，
        // 必须在此兜底加载，否则列表空白、数据残留。
        this.getLists();
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

        getLists() {
            this.loading = true;
            // 加载前清空旧数据，避免换号后残留上一账号的列表
            this.lists = [];
            this.total = 0;
            const data = {
                page: this.currentPage,
                pagesize: this.pageSize,
            };
            if (this.keyword) data.keyword = this.keyword;
            if (this.status) data.status = this.status;
            this.$store.dispatch('call', {
                url: 'recruitment/lists',
                data,
            }).then(({data}) => {
                this.lists = data.data || [];
                this.total = data.total || 0;
            }).catch((err) => {
                this.lists = [];
                this.total = 0;
                $A.modalError({content: err.msg});
            }).finally(() => {
                this.loading = false;
            });
        },

        onSearch() {
            this.currentPage = 1;
            this.getLists();
        },

        onPageChange(page) {
            this.currentPage = page;
            this.getLists();
        },

        openDetail(item) {
            this.goForward({path: '/manage/recruitment/' + item.id});
        },

        showAdd() {
            this.addData = {
                project_mode: 'create',
                name: '',
                desc: '',
                project_id: null,
                title: '',
                description: '',
                skills: '',
                headcount: 1,
                deadline: '',
            };
            this.addStep = 0;
            this.addShow = true;
            this.getMyProjects();
        },

        getMyProjects() {
            this.projectLoading = true;
            this.$store.dispatch('call', {
                url: 'project/lists',
                data: {archived: 'no'},
            }).then(({data}) => {
                // 加载当前用户参与的全部项目（含自己负责 + 他人账号创建、我是成员的项目）
                const list = data.data || [];
                this.myProjects = list;
            }).catch((err) => {
                $A.modalError({content: err.msg});
            }).finally(() => {
                this.projectLoading = false;
            });
        },

        // 按项目名关键词过滤下拉选项（filterable 搜索）
        filterProjects(query, option) {
            if (!query) {
                return true;
            }
            const name = option.label || option.value || '';
            return String(name).toLowerCase().indexOf(String(query).toLowerCase()) > -1;
        },

        // 步骤一 -> 步骤二：校验项目关联，并从项目带出默认招募信息
        nextToRecruit() {
            if (this.addData.project_mode === 'create') {
                if (!this.addData.name || String(this.addData.name).trim().length < 2) {
                    $A.modalWarning({content: this.$L('项目名称不可以少于2个字')});
                    return;
                }
                // 新建项目：标题/简介默认取项目名/项目介绍
                if (!this.addData.title) {
                    this.addData.title = this.addData.name;
                }
                if (!this.addData.description) {
                    this.addData.description = this.addData.desc;
                }
            } else {
                if (!this.addData.project_id) {
                    $A.modalWarning({content: this.$L('请选择项目')});
                    return;
                }
                const p = this.myProjects.find(x => x.id === this.addData.project_id);
                if (p) {
                    if (!this.addData.title) {
                        this.addData.title = p.name;
                    }
                    if (!this.addData.description) {
                        this.addData.description = p.desc;
                    }
                }
            }
            this.addStep = 1;
        },

        submitAdd() {
            this.$refs.addForm.validate((valid) => {
                if (!valid) return;
                this.submitting = true;
                const data = {
                    project_mode: this.addData.project_mode,
                    title: this.addData.title,
                    description: this.addData.description,
                    skills: this.addData.skills,
                    headcount: this.addData.headcount,
                };
                if (this.addData.project_mode === 'create') {
                    data.name = this.addData.name;
                    data.desc = this.addData.desc;
                } else {
                    data.project_id = this.addData.project_id;
                }
                if (this.addData.deadline) {
                    data.deadline = $A.formatDate('Y-m-d H:i:s', this.addData.deadline);
                }
                this.$store.dispatch('call', {
                    url: 'recruitment/add',
                    data,
                }).then(() => {
                    $A.modalSuccess({content: this.$L('发布成功')});
                    this.addShow = false;
                    this.getLists();
                }).catch((err) => {
                    $A.modalError({content: err.msg});
                }).finally(() => {
                    this.submitting = false;
                });
            });
        },
    },
}
</script>

<style scoped>
.page-recruitment {
    height: 100%;
    display: flex;
    flex-direction: column;
    padding: 20px;
}
.recruitment-toolbar {
    display: flex;
    gap: 10px;
    margin-bottom: 16px;
    align-items: center;
}
.recruitment-list {
    flex: 1;
    overflow: auto;
    position: relative;
}
.recruitment-empty {
    text-align: center;
    color: #999;
    padding: 40px 0;
}
.recruitment-card {
    background: #fff;
    border: 1px solid #e8e8e8;
    border-radius: 6px;
    padding: 16px;
    margin-bottom: 12px;
    cursor: pointer;
    transition: box-shadow 0.2s;
}
.recruitment-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.recruitment-card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.recruitment-title {
    font-size: 16px;
    font-weight: 600;
    color: #333;
}
.recruitment-status {
    font-size: 12px;
    padding: 2px 8px;
    border-radius: 10px;
}
.status-recruiting {background: #e6f7ff; color: #1890ff;}
.status-full {background: #fff7e6; color: #fa8c16;}
.status-in_progress {background: #f6ffed; color: #52c41a;}
.status-completed {background: #f9f0ff; color: #722ed1;}
.status-closed {background: #f5f5f5; color: #999;}
.recruitment-desc {
    color: #666;
    font-size: 13px;
    margin-bottom: 8px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.recruitment-tags {margin-bottom: 8px;}
.recruitment-meta {
    display: flex;
    gap: 16px;
    color: #999;
    font-size: 12px;
}
.recruitment-source-tag {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    color: #1890ff;
}
.project-owner-tag,
.project-member-tag {
    float: right;
    font-size: 12px;
    padding: 0 6px;
    border-radius: 8px;
    margin-left: 8px;
}
.project-owner-tag {
    background: #e6f7ff;
    color: #1890ff;
}
.project-member-tag {
    background: #f6ffed;
    color: #52c41a;
}
.recruitment-page {
    display: flex;
    justify-content: center;
    padding-top: 12px;
}
.recruitment-steps {
    margin-bottom: 20px;
}
.add-step-body {
    min-height: 120px;
    position: relative;
    padding: 0 4px;
}
.add-step-body .ivu-spin-fix {
    background: rgba(255,255,255,0.7);
}
.add-empty-tip {
    color: #999;
    font-size: 13px;
    padding: 4px 0 8px 80px;
}
.add-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #f0f0f0;
}
</style>
