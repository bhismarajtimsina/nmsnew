<script lang="ts">
import { ButtonStyled } from './styled';
import { defineComponent, ref } from 'vue';

const Button = defineComponent({
  name: 'Button',
  components: {
    ButtonStyled,
  },
  props: {
    type: {
      type: String,
      validator: (value) =>
        [
          'primary',
          'secondary',
          'success',
          'info',
          'warning',
          'danger',
          'link',
          'dark',
          'light',
          'white',
          'dashed',
          'error',
          'default',
        ].includes(value as string),
      required: false,
      default: 'default',
    },
    shape: {
      type: String,
      default: '',
    },
    icon: {
      type: String,
      default: '',
    },
    size: {
      type: String,
      validator: (value) => ['lg', 'default', 'sm'].includes(value as string),
      default: 'default',
    },
    outlined: {
      type: Boolean,
      default: false,
    },
    ghost: {
      type: Boolean,
      default: false,
    },
    transparent: {
      type: Boolean,
      default: false,
    },
    raised: {
      type: Boolean,
      default: false,
    },
    squared: {
      type: Boolean,
      default: false,
    },
    color: {
      type: String,
      default: '',
    },
    social: {
      type: String,
      default: '',
    },
    loading: {
      type: Boolean,
      default: false,
    },
    load: {
      type: Boolean,
      default: false,
    },
    block: {
      type: Boolean,
      default: false,
    },
    transparented: {
      type: Boolean,
      default: false,
    },
    disabled: {
      type: Boolean,
      default: false,
    },
  },
  setup() {
    // NB: this used to `return { ...props }` here, which spreads the
    // reactive props Proxy into a plain object ONCE at setup time — every
    // prop the template then read (disabled, loading, type, ...) was a
    // frozen snapshot from first mount, so any prop that changed later
    // (e.g. :disabled bound to a computed selection count) silently never
    // updated in the DOM. Options-API components already expose declared
    // props reactively to the template on their own; the spread was both
    // redundant and the actual cause of that bug.
    //
    // selfLoading/enterLoading were meant to be an internal spinner for
    // buttons using the `load` prop instead of an externally-controlled
    // `loading` prop, but the click wiring below (`load && enterLoading`,
    // never actually invoked) never turned it on — pre-existing and out of
    // scope here. Kept under a name that can't collide with the `loading`
    // prop, since that collision was the actual bug being fixed.
    const selfLoading = ref(false);

    function enterLoading() {
      selfLoading.value = true;
    }

    return {
      enterLoading,
    };
  },
});

export default Button;
</script>

<template>
  <ButtonStyled
    :squared="squared"
    :outlined="outlined"
    :ghost="ghost"
    :transparent="transparented"
    :raised="raised"
    :data="type"
    :size="size"
    :shape="shape"
    :type="type"
    :icon="icon"
    :color="color"
    :social="social"
    :click="() => load && enterLoading"
    :loading="loading"
    :block="block"
    :disabled="disabled"
    :class="`ant-btn ant-btn-${type} ant-btn-${size} ${shape && `ant-btn-${shape}`} ${
      block && `ant-btn-block custom-btn`
    }`"
  >
    <slot></slot>
  </ButtonStyled>
</template>
