import { createStore } from 'vuex';
import auth from './modules/authentication/axios/actionCreator';
import themeLayout from './modules/themeLayout/actionCreator';

export default createStore({
  modules: {
    themeLayout,
    auth,
  },
});
