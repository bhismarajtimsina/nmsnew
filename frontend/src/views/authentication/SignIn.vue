<script lang="ts">
import { computed, reactive, defineComponent, ref } from 'vue';
import { isSigningIn, signIn } from '@/auth/session';
import { AuthWrapper } from './style';
import { useRouter } from 'vue-router';
import { notification } from 'ant-design-vue';

const SignIn = defineComponent({
  name: 'SignIn',
  components: { AuthWrapper },
  setup() {
    const isLoading = computed(() => isSigningIn());
    const rememberMe = ref(false);
    const router = useRouter();
    // Only the new API asks for a second factor; the legacy sign-in never sets this.
    const needs2fa = ref(false);

    const formState = reactive({
      login: '',
      password: '',
      twofaPin: '',
    });

    const handleSubmit = async () => {
      const result = await signIn({
        login: formState.login,
        password: formState.password,
        twofaPin: needs2fa.value ? formState.twofaPin : undefined,
      });
      if (result.ok) {
        notification.success({
          message: 'Signed in',
          description: `Welcome back, ${result.userName}.`,
        });
        router.push('/');
      } else if (result.needs2fa) {
        needs2fa.value = true;
        notification.info({
          message: 'Two-factor code required',
          description: 'Enter the 6-digit code from your authenticator app.',
        });
      } else {
        notification.error({
          message: 'Sign in failed',
          description: result.error || 'Sign in failed — check your credentials.',
        });
      }
    };

    return {
      isLoading,
      rememberMe,
      handleSubmit,
      formState,
      needs2fa,
    };
  },
});

export default SignIn;
</script>

<template>
  <div class="signin-wrap">
    <div class="signin-mobile-brand">
      <img src="/src/assets/img/logo-cybersathy-full.png" alt="CyberSathy" />
    </div>
    <AuthWrapper>
      <div class="ninjadash-authentication-top">
        <div class="brand-badge">
          <unicon name="signal-alt-3"></unicon>
          <span>CyberSathy NMS</span>
        </div>
        <h2 class="ninjadash-authentication-top__title">Welcome back</h2>
        <p class="signin-subtitle">Sign in to continue to the panel.</p>
      </div>
      <div class="ninjadash-authentication-content">
        <a-form @finish="handleSubmit" :model="formState" layout="vertical">
          <a-form-item name="login" label="Username">
            <a-input v-model:value="formState.login" autocomplete="username" />
          </a-form-item>
          <a-form-item name="password" label="Password">
            <a-input
              type="password"
              v-model:value="formState.password"
              placeholder="Password"
              autocomplete="current-password"
            />
          </a-form-item>
          <a-form-item v-if="needs2fa" name="twofaPin" label="Authenticator code">
            <a-input
              v-model:value="formState.twofaPin"
              inputmode="numeric"
              maxlength="8"
              autocomplete="one-time-code"
              placeholder="6-digit code"
            />
          </a-form-item>
          <div class="ninjadash-auth-extra-links">
            <a-checkbox v-model:checked="rememberMe">Keep me logged in</a-checkbox>
          </div>
          <a-form-item>
            <sdButton class="btn-signin" htmlType="submit" type="primary" :disabled="isLoading">
              {{ isLoading ? 'Signing in...' : 'Sign In' }}
            </sdButton>
          </a-form-item>
        </a-form>
      </div>
    </AuthWrapper>
  </div>
</template>

<style scoped>
.signin-wrap {
  width: 100%;
  max-width: 420px;
}
.signin-mobile-brand {
  display: none;
  justify-content: center;
  margin-bottom: 28px;
}
.signin-mobile-brand img {
  height: 44px;
  width: auto;
}
.signin-subtitle {
  margin: 6px 0 0;
  font-size: 13px;
  color: #8c90a4;
}
.brand-badge {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 7px 16px;
  margin-bottom: 16px;
  border-radius: 999px;
  background: linear-gradient(135deg, #1868db, #2f6fed);
  box-shadow: 0 6px 16px rgba(24, 104, 219, 0.28);
}
.brand-badge span {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.6px;
  text-transform: uppercase;
  color: #ffffff;
}
.brand-badge :deep(svg) {
  width: 14px;
  height: 14px;
  fill: #ffffff;
}
@media (max-width: 991px) {
  .signin-mobile-brand {
    display: flex;
  }
}
</style>
