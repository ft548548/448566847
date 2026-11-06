<template>
    <div class="page-login">
        <PageTitle :title="$L('登录')"/>
        <div class="login-body">
            <div class="login-logo no-dark-mode" :class="{'can-click':needStartHome}" @click="goHome"></div>
            <div class="login-box">
                <div class="login-title">{{welcomeTitle}}</div>

                <div v-if="loginType=='reg'" class="login-subtitle">{{$L('输入您的信息以创建帐户。')}}</div>
                <div v-else class="login-subtitle">{{$L('输入您的凭证以访问您的帐户。')}}</div>

                <div class="login-input">
                    <div v-if="$Electron && cacheServerUrl" class="login-field">
                        <div class="login-label"><span>{{$L('服务器地址')}}</span></div>
                        <Input :value="$A.getDomain(cacheServerUrl)" prefix="ios-globe-outline" size="large" readonly clearable @on-clear="clearServerUrl"/>
                    </div>

                    <div class="login-field">
                        <div class="login-label"><span>{{$L('电子邮件地址')}}</span></div>
                        <Input v-model="email" prefix="ios-mail-outline" :placeholder="$L('输入您的电子邮件')" size="large" @on-enter="onLogin" @on-blur="onBlur" />
                    </div>

                    <div class="login-field">
                        <div class="login-label">
                            <span>{{$L('密码')}}</span>
                            <a v-if="loginType=='login'" class="login-label-extra" href="javascript:void(0)" @click="forgotPassword">{{$L('忘记密码了？')}}</a>
                        </div>
                        <Input v-model="password" prefix="ios-lock-outline" :placeholder="$L('输入您的密码')" type="password" size="large" @on-enter="onLogin" />
                    </div>

                    <div v-if="loginType=='reg'" class="login-field">
                        <div class="login-label"><span>{{$L('确认密码')}}</span></div>
                        <Input v-model="password2" prefix="ios-lock-outline" :placeholder="$L('输入确认密码')" type="password" size="large" @on-enter="onLogin" />
                    </div>

                    <div v-if="loginType=='reg' && needInvite" class="login-field">
                        <div class="login-label"><span>{{$L('邀请码')}}</span></div>
                        <Input v-model="invite" :placeholder="$L('请输入注册邀请码')" type="text" size="large" @on-enter="onLogin" />
                    </div>

                    <div v-if="loginType=='login' && codeNeed" class="login-field">
                        <div class="login-label"><span>{{$L('图形验证码')}}</span></div>
                        <Input v-model="code" class="login-code" :placeholder="$L('输入图形验证码')" size="large" @on-enter="onLogin">
                            <Icon type="ios-checkmark-circle-outline" class="login-icon" slot="prepend"></Icon>
                            <div slot="append" class="login-code-end" @click="reCode"><img :src="codeUrl"/></div>
                        </Input>
                    </div>

                    <Button class="login-submit" type="primary" :loading="loadIng > 0 || loginJump" size="large" long @click="onLogin">{{$L(loginText)}}</Button>

                    <!-- 第三方登录：占位形态，仅提供视觉入口，不发起任何请求；接入方式见 methods.onOauthPlaceholder 注释 -->
                    <div v-if="loginType=='login'" class="login-oauth">
                        <div class="login-divider"><span>{{$L('或者')}}</span></div>
                        <button type="button" class="login-oauth-btn" aria-disabled="true" :title="$L('该登录方式暂未开放')" @click="onOauthPlaceholder('Google')">
                            <svg class="login-oauth-icon" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
                                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                                <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                                <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                            </svg>
                            <span>{{$L('继续使用 Google')}}</span>
                        </button>
                        <button type="button" class="login-oauth-btn" aria-disabled="true" :title="$L('该登录方式暂未开放')" @click="onOauthPlaceholder('Apple')">
                            <svg class="login-oauth-icon login-oauth-icon-apple" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path fill="currentColor" d="M16.365 1.43c0 1.14-.42 2.2-1.26 3.03-.9.9-1.96 1.4-3.06 1.31-.02-1.14.44-2.23 1.24-3.05.86-.88 2.02-1.42 3.08-1.29zM20.7 17.13c-.5 1.15-.74 1.66-1.38 2.68-.9 1.42-2.16 3.2-3.73 3.21-1.39.02-1.75-.9-3.63-.89-1.88.01-2.27.92-3.66.9-1.57-.02-2.77-1.61-3.66-3.03-2.51-3.98-2.77-8.65-1.22-11.14 1.1-1.76 2.83-2.8 4.46-2.8 1.66 0 2.7.91 4.07.91 1.33 0 2.14-.91 4.06-.91 1.45 0 2.99.79 4.09 2.16-3.6 1.97-3.01 7.11.26 8.91z"/>
                            </svg>
                            <span>{{$L('继续使用 Apple')}}</span>
                        </button>
                    </div>
                </div>
            </div>
            <div class="login-switch">
                <template v-if="loginType=='reg'">{{$L('已经有帐号？')}}<a href="javascript:void(0)" @click="loginType='login'">{{$L('登录帐号')}}</a></template>
                <template v-else>{{$L('还没有帐号？')}}<a href="javascript:void(0)" @click="loginType='reg'">{{$L('注册帐号')}}</a></template>
            </div>
            <div class="login-bottom">
                <Dropdown trigger="click" placement="bottom-start">
                    <div class="login-setting">
                        {{$L('设置')}}
                        <i class="taskfont">&#xe689;</i>
                    </div>
                    <DropdownMenu slot="list" class="login-setting-menu">
                        <Dropdown placement="right-start" transfer @on-click="setTheme">
                            <DropdownItem>
                                <div class="login-setting-item">
                                    {{$L('主题皮肤')}}
                                    <Icon type="ios-arrow-forward"></Icon>
                                </div>
                            </DropdownItem>
                            <DropdownMenu slot="list">
                                <DropdownItem
                                    v-for="(item, key) in themeList"
                                    :key="key"
                                    :name="item.value"
                                    :selected="themeMode === item.value">{{$L(item.name)}}</DropdownItem>
                            </DropdownMenu>
                        </Dropdown>
                        <Dropdown placement="right-start" transfer @on-click="setLanguage">
                            <DropdownItem divided>
                                <div class="login-setting-item">
                                    {{currentLanguage}}
                                    <Icon type="ios-arrow-forward"></Icon>
                                </div>
                            </DropdownItem>
                            <DropdownMenu slot="list">
                                <DropdownItem
                                    v-for="(item, key) in languageList"
                                    :key="key"
                                    :name="key"
                                    :selected="getLanguage() === key">{{item}}</DropdownItem>
                            </DropdownMenu>
                        </Dropdown>
                    </DropdownMenu>
                </Dropdown>
            </div>
        </div>
    </div>
</template>

<script>
import {mapState} from "vuex";
import {Store} from "le5le-store";

export default {
    data() {
        return {
            loadIng: 0,

            codeNeed: false,
            codeUrl: $A.apiUrl('users/login/codeimg?_=' + Math.random()),

            loginType: 'login',
            loginJump: false,

            email: $A.getStorageString("cacheLoginEmail") || '',
            password: '',
            password2: '',
            code: '',
            invite: '',

            needStartHome: false,

            needInvite: false,

            subscribe: null,
        }
    },
    mounted() {
        this.getDemoAccount();
        this.getNeedStartHome();
        //
        if (this.$Electron) {
            this.chackServerUrl().catch(_ => {});
        } else {
            this.clearServerUrl();
        }
        //
        this.subscribe = Store.subscribe('useSSOLogin', () => {
            this.inputServerUrl();
        });
    },
    beforeDestroy() {
        if (this.subscribe) {
            this.subscribe.unsubscribe();
            this.subscribe = null;
        }
    },
    activated() {
        this.loginType = 'login'
        //
        if (this.$Electron) {
            this.$Electron.sendMessage('subWindowDestroyAll')
        }
    },
    deactivated() {
        this.loginJump = false;
        this.password = "";
        this.password2 = "";
        this.code = "";
        this.invite = "";
    },
    computed: {
        ...mapState([
            'cacheServerUrl',

            'themeMode',
            'themeList',
        ]),

        currentLanguage() {
            return this.languageList[this.languageType] || 'Language'
        },

        welcomeTitle() {
            return this.$L('欢迎使用') + (window.systemInfo.title || "警务实训平台");
        },

        loginText() {
            let text = this.loginType == 'login' ? '登录' : '注册';
            if (this.loginJump) {
                text += "成功..."
            }
            return text
        }
    },
    watch: {
        '$route' ({query}) {
            if (query.type=='reg'){
                this.$nextTick(()=>{
                    this.loginType = "reg"
                })
            }
        },
        loginType(val) {
            if (val == 'reg') {
                this.getNeedInvite();
            }
        }
    },
    methods: {
        goHome() {
            if (this.needStartHome) {
                this.goForward({name: 'index'});
            }
        },

        setTheme(mode) {
            this.$store.dispatch("setTheme", mode)
        },

        getDemoAccount() {
            if (this.isNotServer()) {
                return;
            }
            this.$store.dispatch("call", {
                url: 'system/demo',
            }).then(({data}) => {
                if (data.account) {
                    this.email = data.account;
                    this.password = data.password;
                }
            }).catch(_ => {
                //
            });
        },

        getNeedStartHome() {
            if (this.isNotServer()) {
                return;
            }
            this.$store.dispatch("call", {
                url: "system/get/starthome",
            }).then(({data}) => {
                this.needStartHome = !!data.need_start;
            }).catch(_ => {
                this.needStartHome = false;
            });
        },

        getNeedInvite() {
            this.$store.dispatch("call", {
                url: 'users/reg/needinvite',
            }).then(({data}) => {
                this.needInvite = !!data.need;
            }).catch(_ => {
                this.needInvite = false;
            });
        },

        forgotPassword() {
            $A.modalWarning("请联系管理员！");
        },

        /**
         * 第三方登录占位入口
         *
         * 当前仅提供视觉占位，不接入任何真实登录能力：
         * 不跳转、不请求 OAuth 授权端点、不调用 /api/v1/workshop/auth/* ，
         * 也不写入任何本地状态。后续接入时在此处替换为真实授权跳转即可。
         *
         * @param {String} provider 提供方名称（Google / Apple）
         */
        onOauthPlaceholder(provider) {
            $A.modalWarning(this.$L("% 登录暂未开放，请使用邮箱密码登录。", provider));
        },

        reCode() {
            this.codeUrl = $A.apiUrl('users/login/codeimg?_=' + Math.random())
        },

        inputServerUrl() {
            $A.modalInput({
                title: "使用 SSO 登录",
                value: this.cacheServerUrl,
                placeholder: "请输入服务器地址",
                onOk: (value, cb) => {
                    if (value) {
                        if (!$A.leftExists(value, "http://") && !$A.leftExists(value, "https://")) {
                            value = "http://" + value;
                        }
                        if (!$A.rightExists(value, "/api/")) {
                            value = value + ($A.rightExists(value, "/") ? "api/" : "/api/");
                        }
                        this.$store.dispatch("call", {
                            url: value + 'system/setting',
                        }).then(() => {
                            this.setServerUrl(value)
                            cb()
                        }).catch(({msg}) => {
                            $A.modalError(msg || "服务器地址无效", 301);
                            cb()
                        });
                        return;
                    }
                    this.clearServerUrl();
                }
            });
        },

        chackServerUrl(tip) {
            return new Promise((resolve, reject) => {
                if (this.isNotServer()) {
                    if (tip === true) {
                        $A.messageWarning("请设置服务器")
                    }
                    this.inputServerUrl()
                    reject()
                } else {
                    resolve()
                }
            })
        },

        setServerUrl(value) {
            if (value != this.cacheServerUrl) {
                $A.setStorage("cacheServerUrl", value)
                window.location.reload();
            }
        },

        clearServerUrl() {
            this.setServerUrl("")
        },

        isNotServer() {
            let apiHome = $A.getDomain(window.systemInfo.apiUrl)
            return this.$Electron && (apiHome == "" || apiHome == "public")
        },

        onBlur() {
            if (this.loginType != 'login' || !this.email) {
                this.codeNeed = false;
                return;
            }
            this.loadIng++;
            this.$store.dispatch("call", {
                url: 'users/login/needcode',
                data: {
                    email: this.email,
                },
            }).then(() => {
                this.loadIng--;
                this.reCode();
                this.codeNeed = true;
            }).catch(_ => {
                this.loadIng--;
                this.codeNeed = false;
            });
        },

        onLogin() {
            this.chackServerUrl(true).then(() => {
                this.email = $A.trim(this.email)
                this.password = $A.trim(this.password)
                this.password2 = $A.trim(this.password2)
                this.code = $A.trim(this.code)
                this.invite = $A.trim(this.invite)
                //
                if (!$A.isEmail(this.email)) {
                    $A.messageWarning("请输入正确的邮箱地址");
                    return;
                }
                if (!this.password) {
                    $A.messageWarning("请输入密码");
                    return;
                }
                if (this.loginType == 'reg') {
                    if (this.password != this.password2) {
                        $A.messageWarning("确认密码输入不一致");
                        return;
                    }
                }
                this.loadIng++;
                this.$store.dispatch("call", {
                    url: 'users/login',
                    data: {
                        type: this.loginType,
                        email: this.email,
                        password: this.password,
                        code: this.code,
                        invite: this.invite,
                    },
                }).then(({data}) => {
                    this.loadIng--;
                    this.codeNeed = false;
                    $A.setStorage("cacheLoginEmail", this.email)
                    this.$store.dispatch("handleClearCache", data).then(() => {
                        this.goNext1();
                    }).catch(_ => {
                        this.goNext1();
                    });
                }).catch(({data, msg}) => {
                    this.loadIng--;
                    if (data.code === 'email') {
                        $A.modalWarning(msg);
                    } else {
                        $A.modalError(msg);
                    }
                    if (data.code === 'need') {
                        this.reCode();
                        this.codeNeed = true;
                    }
                });
            })
        },

        goNext1() {
            this.loginJump = true;
            if (this.loginType == 'login') {
                this.goNext2();
            } else {
                // 新注册自动创建项目
                this.$store.dispatch("call", {
                    url: 'project/add',
                    data: {
                        name: this.$L('个人项目'),
                        desc: this.$L('注册时系统自动创建项目，你可以自由删除。')
                    },
                }).then(() => {
                    this.goNext2();
                }).catch(_ => {
                    this.goNext2();
                });
            }
        },

        goNext2() {
            let fromUrl = decodeURIComponent($A.getObject(this.$route.query, 'from'));
            if (fromUrl) {
                window.location.replace(fromUrl);
            } else {
                this.goForward({name: 'manage-dashboard'}, true);
            }
        }
    }
}
</script>
