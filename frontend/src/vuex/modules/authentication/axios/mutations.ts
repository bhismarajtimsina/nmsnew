export default {
  loginBegin(state: any) {
    state.loading = true;
    state.error = null;
  },
  loginSuccess(state: any, user: any) {
    state.loading = false;
    state.login = true;
    state.user = user;
  },

  loginErr(state: any, err: any) {
    state.loading = false;
    state.login = false;
    state.error = err;
  },

  logoutBegin(state: any) {
    state.loading = true;
  },

  logoutSuccess(state: any) {
    state.loading = false;
    state.login = false;
    state.user = null;
  },

  logoutErr(state: any, err: any) {
    state.loading = false;
    state.error = err;
  },
};
