function u(l,t){let e=null;return{call(){e&&clearTimeout(e),e=setTimeout(l,t)},cancel(){e&&clearTimeout(e),e=null}}}export{u as d};
