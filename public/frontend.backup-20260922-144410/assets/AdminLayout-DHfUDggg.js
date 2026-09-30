import{a as e}from"./rolldown-runtime-B0Z9INg1.js";import{B as t,H as n,L as r,N as i,Nt as a,Pt as o,Q as s,V as c,W as l,X as u,Y as d,Z as f,at as p,b as m,d as h,dt as g,f as _,h as v,i as y,it as b,j as x,jt as S,m as C,o as w,p as T,s as E,ut as D,v as O,x as k,y as A}from"./runtime-core.esm-bundler-BlqgyoL8.js";import{At as j,Nt as M,Pt as N,Tt as ee,jt as te,n as P}from"./vue-styled-components.es-DCAh8igK.js";import{n as ne,r as re,t as ie}from"./menu-adiH4zRX.js";import{t as ae}from"./dayjs.min-BcJkixEx.js";import{T as oe,a as se,f as ce,i as le,j as ue,n as de,o as F,p as I,r as fe,t as L,w as pe}from"./index-DJEHzulI.js";import"./css-byK8Xma3.js";import{t as me}from"./logo-cybersathy-full-B05NbLIu.js";import"./css-gAXaORY-.js";import{t as he}from"./relativeTime-hZFJ5afi.js";import{t as ge}from"./utilities-Dct4ePNC.js";var _e=`/assets/logo-cybersathy-icon-DKZLcCu9.png`,R=k([`hide`,`searchHide`,`darkMode`,`topMenu`]),ve=P.p`
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    color: rgb(146, 153, 184);
    padding: 0px 15px;
    display: flex;
`,ye=P(`div`,R)`
    .ant-layout {
        background-color: transparent;
        .ant-layout-header{
            padding: ${({theme:e})=>e.rtl?`0 0 0 30px`:`0 30px 0 0`};
            height: 72px;
            @media only screen and (max-width: 991px){
                padding: 0 15px;
            }
        }
    }
    .ant-layout.layout {
        background-color: ${({theme:e})=>e[e.mainContent][`main-background`]} !important;
    }

    .ninjadash-nav-actions__searchbar{
        display: flex;
        align-items: center;
        svg,
        img{
            width: 16px;
            height: 16px;
            color: ${({theme:e})=>e[e.mainContent][`light-text`]};
            fill: ${({theme:e})=>e[e.mainContent][`light-text`]};
        }
        .ninjadash-searchbar{
            opacity: 0;
            visibility: hidden;
            transition: .35s;
            @media only screen and (max-width: 767px){
                position: fixed;
                top: 45px;
                right: 0;
                min-width: 280px;
                z-index: 98;
                box-shadow: 0 5px 30px ${({theme:e})=>e[`gray-solid`]}15;
            }
            input{
                user-select: none;
                pointer-events: none;
                &:focus{
                    outline: none;
                    box-shadow: 0 0;
                }
            }
        }
        &.show{
            .ninjadash-searchbar{
                opacity: 1;
                visibility: visible;
                input{
                    user-select: all;
                    pointer-events: all;
                }
            }
            .ninjadash-search-icon{
                display: none;
            }
            .ninjadash-close-icon{
                display: block !important;
            }
        }
        .ninjadash-close-icon{
            display: none !important;
        }
        a{
            line-height: .8;
            position: relative;
            top: 0;
        }
    }

    /* ninjadash Header Style */
    .ninjadash-header-content{
        height: 100%;
        .ninjadash-header-content__left{
            min-width: 280px;
            padding: 0 20px 0 30px;
            background-color: ${({theme:e})=>e[e.mainContent][`brand-background`]};
            @media only screen and (max-width: 767px){
                min-width: auto;
                margin-right: 0;
								padding-left: 0;
            }
            .navbar-brand{
                display: flex;
                justify-content: space-between;
                align-items: center;
                button{
                    padding: 0;
                    line-height: 0;
                    margin-top: 4px;

                    color: ${({theme:e})=>e[e.mainContent][`extra-light`]};
                    background-color: ${({theme:e})=>e[e.mainContent][`brand-background`]};
                    :hover {
                        background-color: ${({theme:e})=>e[e.mainContent][`brand-background`]} !important;
                    }

                    @media only screen and (max-width: 875px){
                        padding: ${({theme:e})=>e.rtl?`0 10px 0 20px`:`0 20px 0 10px`};
                    }
                    @media only screen and (max-width: 767px){
                        order: -1;
                        padding: ${({theme:e})=>e.rtl?`0 0 0 15px`:`0 15px 0 0`};
                    }
                }
            }
            .ninjadash-logo{
                @media only screen and (max-width: 875px){
                    ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 4px;
                }
                @media only screen and (max-width: 767px){
                    ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 0;
                }
                img{
                    max-width: ${({theme:e})=>e.topMenu?`140px`:`120px`};
                    width: 100%;
                    @media only screen and (max-width: 475px){
                        max-width: ${({theme:e})=>(e.topMenu,`100px`)};
                    }
                }
                &.top-menu{
                    ${({theme:e})=>e.rtl?`margin-right`:`margin-left`}: 15px;
                }
            }
        }
        .ninjadash-header-content__right{
            flex: auto;
            svg{
                fill: ${({theme:e})=>e[e.mainContent][`light-text`]}};
            }
            .ninjadash_menu-item-icon {
                fill: transparent !important;
                stroke: ${({theme:e})=>e[e.mainContent][`light-text`]}};
            }
            .ninjadash-nav-actions{
                display: flex;
                justify-content: flex-end;
                align-items: center;
                flex: auto;
                @media only screen and (max-width: 767px){
                    display: none;
                }
                .ninjadash-nav-actions__language,
                .ninjadash-nav-actions__author{
                    line-height: 1;
                }
                .ninjadash-nav-actions__searchbar{
                    margin-right: 8px;
                    margin-top: -4px;
                }
                .ninjadash-nav-actions__author{
                    margin: 0 3px;
                    .ninjadash-nav-action-link{
                        display: flex;
                        align-items: center;
                        i,
                        svg,
                        img {
                            width: 16px;
                            height: 16px;
                            color: ${({theme:e})=>e[e.mainContent][`light-text`]}};
                            fill: ${({theme:e})=>e[e.mainContent][`light-text`]}};
                        }
                        svg{
                            fill: ${({theme:e})=>e[e.mainContent][`light-text`]}};
                        }
                        .ant-avatar-image{
                            img{
                                min-width: 32px;
                                max-width: 32px;
                                min-height: 32px;
                            }
                        }
                    }
                }
                .ninjadash-nav-actions__author--name{
                    font-size: 14px;
                    display: inline-block;
                    font-weight: 500;
                    margin: ${({theme:e})=>e.rtl?`0 10px 0 6px`:`0 6px 0 10px`};
                    color: ${({theme:e})=>e[e.mainContent][`gray-text`]};
                    @media only screen and (max-width: 991px){
                        display: none;
                    }
                }
            }
        }
        .ninjadash-header-content__fluid{
            display: none;
            @media only screen and (max-width: 767px){
                display: block;
            }
            .ninjadash-header-content__fluid__action{
                position: absolute;
                ${({theme:e})=>e.rtl?`left`:`right`}: 20px;
                top: 50%;
                transform: translateY(-50%);
                display: inline-flex;
                align-items: center;
                @media only screen and (max-width: 767px){
                    ${({theme:e})=>e.rtl?`left`:`right`}: 15px;
                }
                a,
                .btn-search{
                    display: inline-flex;
                    color: ${({theme:e})=>e[`light-color`]};
                    &.btn-search{
                        ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 18px;
                        @media only screen and (max-width: 475px){
                            ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 8px;
                        }
                    }
                    svg{
                        width: 18px;
                        height: 18px;
					    fill: ${({theme:e})=>e[`light-color`]};
                    }
                }
                .ninjadash-searchbar{
                    .ant-input{
                        border: 0 none;
                    }
                    .ant-row{
                        margin-bottom: 0;
                    }
                }
            }
        }
    }
    .ninjadash-header-more{
        .ninjadash-nav-actions__author{
            .ninjadash-nav-actions__author--name{
                display: none;
            }
            .ninjadash-nav-action-link{
                display: flex;
                align-items: center;
                .ant-avatar-image{
                    ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 5px;
                }
                svg{
                    width: 20px;
                    height: 20px;
                    fill: ${({theme:e})=>e[e.mainContent][`light-text`]}};
                }
            }
        }
        .ninjadash-nav-actions__message,
        .ninjadash-nav-actions__notification,
        .ninjadash-nav-actions__settings,
        .ninjadash-nav-actions__language{
            position: relative;
            top: 4px;
        }
    }
    header{
        box-shadow: 0 5px 20px ${({theme:e})=>e[`extra-light-color`]}05;
        z-index: 998;
        background-color: ${({theme:e})=>e[e.mainContent][`white-background`]} !important;
        @media print {
            display: none;
        }
        .ant-menu-sub.ant-menu-vertical{
            .ant-menu-item{
                a{
                    color: ${({theme:e})=>e[`gray-color`]}};
                }
            }
        }
        .ant-menu.ant-menu-horizontal{
            display: flex;
            align-items: center;
            margin: 0 -16px;
            background: ${({theme:e})=>e[e.mainContent][`main-background-light`]};
            border-bottom-color: ${({theme:e})=>e[e.mainContent][`border-color-default`]};
            li.ant-menu-submenu{
                margin: 0 16px;
            }
            .ant-menu-item{
                color: ${({theme:e})=>e[e.mainContent][`gray-text`]};
                &.ant-menu-item-disabled{
                    color: ${({theme:e})=>e[e.mainContent][`gray-light-text`]} !important;
                }
            }
            .ant-menu-submenu{
                &.ant-menu-submenu-active,
                &.ant-menu-submenu-selected,
                &.ant-menu-submenu-open{
                    .ant-menu-submenu-title{
                        color: ${({theme:e})=>e[e.mainContent][`dark-text`]};
                        svg,
                        i{
                            color: ${({theme:e})=>e[e.mainContent][`dark-text`]};
                            fill: ${({theme:e})=>e[e.mainContent][`dark-text`]};
                        }
                    }
                }
                .ant-menu-submenu-title{
                    font-size: 14px;
                    font-weight: 500;
                    color: ${({theme:e})=>e[e.mainContent][`dark-text`]};
                    svg,
                    i{
                        color: ${({theme:e})=>e[e.mainContent][`dark-text`]};
                        fill: ${({theme:e})=>e[e.mainContent][`dark-text`]};
                    }
                    .ant-menu-submenu-arrow{
                        font-family: "FontAwesome";
                        font-style: normal;
                        ${({theme:e})=>e.rtl?`margin-right`:`margin-left`}: 6px;
                        &:after{
                            color: ${({theme:e})=>e[e.mainContent][`dark-text`]};
                            content: '\f107';
                            background-color: transparent;
                        }
                    }
                }
            }
        }
        .ant-menu.ant-menu-vertical{
            background: ${({theme:e})=>e[e.mainContent][`main-background-light`]};
            border-right-color: ${({theme:e})=>e[e.mainContent][`border-color-default`]};
            .ant-menu-item{
                color: ${({theme:e})=>e[e.mainContent][`gray-text`]};
                svg{
                    fill: ${({theme:e})=>e[e.mainContent][`gray-text`]};
                }
            }
        }
    }

    /* Sidebar styles */
    .ant-layout-sider {
        box-shadow: 0 0 20px ${({theme:e})=>e[`extra-light-color`]}05;
        @media (max-width: 991px){
            box-shadow: 0 10px 10px #00000020;
        }
        @media print {
            display: none;
        }

        &.ant-layout-sider-collapsed{
            padding: 0 0px 55px !important;
            .ninjadash-sidebar-nav-title{
                display: none !important;
            }
            & + .ninjadash-main-layout{
                ${({theme:e})=>e.rtl?`margin-right`:`margin-left`}: 80px;

            }
            .ant-menu-item{
                color: #333;
                .badge{
                    display: none;
                }
            }
        }

        &.ant-layout-sider-dark {
            background: ${({theme:e})=>e[e.mainContent][`white-background`]} !important;
            .ant-layout-sider-children{
                .ant-menu{
                    .ant-menu-submenu-inline{
                        > .ant-menu-submenu-title{
                            padding: 0 20px !important;
                        }
                    }
                    .ant-menu-item{
                        padding: 0 20px !important;
                    }
                }
            }
        }

        .ant-layout-sider-children{
            padding-bottom: 15px;

            .ninjadash-sidebar-nav-title {
                display: flex;
                font-size: 12px;
                font-weight: 500;
                text-transform: uppercase;
                color: ${({theme:e})=>e[e.mainContent][`gray-text`]};
                padding: 0 ${({theme:e})=>e.rtl?`20px`:`15px`};
                margin: 40px 0 24px 0;
            }

            .ninjadash-sidebar-nav-title{
                &.ninjadash-sidebar-nav-title-top{
                    margin: 8px 0 0;
                }
            }
						/* .scroll-menu{
							height: 100vh;
						} */
            .ant-menu{
                font-size: 14px;
                overflow-x: hidden;
                ${({theme:e})=>e.rtl?`border-left`:`border-right`}: 0 none;
                background: ${({theme:e})=>e[e.mainContent][`white-background`]};
                &.ant-menu-dark, &.ant-menu-dark .ant-menu-sub, &.ant-menu-dark .ant-menu-inline.ant-menu-sub {
                    background-color: ${({theme:e})=>e[e.mainContent][`white-background`]} !important;

                }
                .ant-menu-sub.ant-menu-inline{
                    background-color: ${({theme:e})=>e[e.mainContent][`white-background`]} !important;
                }

                .ant-menu-submenu-selected{
                    color: ${({theme:e})=>e[e.mainContent][`light-text`]};
                }
                .ant-menu-submenu,
                .ant-menu-item{
                    ${({theme:e})=>e.rtl&&`padding-right: 5px;`};
                    &.ant-menu-item-selected{
                        border-radius: 0 25px 25px 0;
                        background-color: ${({theme:e})=>e[`primary-color`]}15;
                        &:after{
                            content: none;
                        }
                    }
                    &.ant-menu-submenu-active{
                        >.ant-menu-submenu-title .ant-menu-title-content{
                            color: ${({theme:e})=>e[e.mainContent][`menu-active`]};
                        }
                        svg{
                            fill: ${({theme:e})=>e[e.mainContent][`menu-active`]};
                        }

                        >.ant-menu-submenu-title{
                            .ant-menu-submenu-arrow:before,
                            .ant-menu-submenu-arrow:after{
                                background-color: ${({theme:e})=>e[e.mainContent][`menu-active`]};
                            }
                        }
                    }
                    &.ant-menu-item-active{
                        .ant-menu-item-icon{
                            svg{
                                fill: ${({theme:e})=>e[e.mainContent][`menu-active`]};
                            }
                        }
                        svg{
                            fill: ${({theme:e})=>e[e.mainContent][`menu-active`]};
                        }
                        .ant-menu-title-content a{
                            color: ${({theme:e})=>e[e.mainContent][`menu-active`]} !important;
                        }
                    }
                    .ant-menu-item-icon{
                        svg{
                            transition: color 0.3s;
                        }
                    }
                    svg,
                    img{
                        width: 16px;
                        font-size: 16px;
                        color: ${({theme:e})=>e[e.mainContent][`menu-icon-color`]};
                        fill: ${({theme:e})=>e[e.mainContent][`menu-icon-color`]};
                        transition: 0.3s ease;
                    }
                    span{
                        display: inline-block;
                        transition: 0.3s ease;
                    }
                    .ant-menu-title-content{
                        ${({theme:e})=>e.rtl?`padding-right`:`padding-left`}: 16px;
                    }
                    &.ant-menu-submenu-selected{
                        svg{
                            fill: ${({theme:e})=>e[`primary-color`]};
                        }
                        .ant-menu-title-content{
                            color: ${({theme:e})=>e[`primary-color`]} !important;
                        }
                    }
                }
                .ant-menu-item{
                    .menuItem-iocn{
                        width: auto;
                    }
                    &:not(:last-child){
                        margin-bottom: 0;
                    }
                    &.ant-menu-item-selected{
                        svg{
                            fill: ${({theme:e})=>e[`primary-color`]};
                        }
                        .ant-menu-title-content{
                            a{
                                color: ${({theme:e})=>e[`primary-color`]} !important;
                            }
                        }
                    }
                }
                .ant-menu-submenu{
                    &.ant-menu-submenu-open{
                        >.ant-menu-submenu-title{
                            display: flex;
                            align-items: center;
                            .title{
                                padding-left: 0;
                            }
                            .badge{
                                ${({theme:e})=>e.rtl?`left`:`right`}: 45px;
                            }
                            span{
                                font-weight: 500;
                                color: ${({theme:e})=>e[e.mainContent][`menu-active`]};
                            }
                            svg,
                            i,
                            .ant-menu-submenu-arrow{
                                color: ${({theme:e})=>e[e.mainContent][`menu-active`]};
                                fill: ${({theme:e})=>e[e.mainContent][`menu-active`]};
                                &:after,
                                &:before{
                                    background-color: ${({theme:e})=>e[e.mainContent][`menu-active`]};
                                }
                            }
                        }
                        .ant-menu-sub{
                            .ant-menu-item{
                                &.ant-menu-item-selected{
                                    background-color: ${({theme:e})=>e[`primary-color`]}15 !important;
                                    border-radius: ${({theme:e})=>e.rtl?`21px 0 0 21px`:`0 21px 21px 0`};
                                    a{
                                        font-weight: 500;
                                        color: ${({theme:e})=>e[e.mainContent][`menu-active`]} !important;
                                    }
                                }
                            }
                        }
                    }
                    .ant-menu-submenu-title{
                        .ant-menu-title-content{
                            font-weight: 500;
                            color: ${({theme:e})=>e[e.mainContent][`gray-text`]};
                            text-align: ${({theme:e})=>e.rtl?`right`:`left`};
                        }
                    }
                }

                .ant-menu-item,
                .ant-menu-submenu-title{
                    margin: 0 !important;
                    &:active{
                        background-color: transparent;
                    }
                    a{
                        font-size: 14px;
                        font-weight: 500;
                        color: ${({theme:e})=>e[`gray-text`]};
                        position: relative;
                    }
                    >span{
                        width: 100%;
                        margin-left: 0;
                        .pl-0{
                            ${({theme:e})=>e.rtl?`padding-right`:`padding-left`}: 0px;
                        }
                    }
                    .badge{
                        position: absolute;
                        ${({theme:e})=>e.rtl?`left`:`right`}: 30px;
                        top: 50%;
                        transform: translateY(-50%);
                        display: inline-block;
                        height: auto;
                        font-size: 10px;
                        border-radius: 3px;
                        padding: 3px 4px 4px;
                        line-height: 1;
                        letter-spacing: 1px;
                        color: #fff;
                        &.badge-primary{
                            background-color: ${({theme:e})=>e[`primary-color`]};
                        }
                        &.badge-success{
                            background-color: ${({theme:e})=>e[`success-color`]};
                        }
                    }
                }

                .ant-menu-submenu-inline{
                    > .ant-menu-submenu-title{
                        display: flex;
                        align-items: center;
                        padding: 0 15px !important;
                        margin: 0;
                        svg,
                        img{
                            width: 16px;
                            height: 16px;
                        }

                        .ant-menu-submenu-arrow{
                            right: auto;
                            ${({theme:e})=>e.rtl?`left`:`right`}: 24px;
                            &:after,
                            &:before{
                                width: 6px;
                                background: #868EAE;
                                height: 1.2px;
                            }
                            &:before{
                                transform: rotate(45deg) ${({theme:e})=>e.rtl?`translateY(3px)`:`translateY(-3px)`};
                            }
                            &:after{
                                transform: rotate(-45deg) ${({theme:e})=>e.rtl?`translateY(-3px)`:`translateY(3px)`};
                            }
                        }
                    }
                    &.ant-menu-submenu-open{
                        > .ant-menu-submenu-title{
                            .ant-menu-submenu-arrow{
                                transform: translateY(2px);
                                &:before{
                                    transform: rotate(45deg) translateX(-3.3px);
                                }
                                &:after{
                                    transform: rotate(-45deg) translateX(3.3px);
                                }
                            }
                        }
                    }
                    .ant-menu-item{
                        ${({theme:e})=>e.rtl?`padding-right`:`padding-left`}: 0 !important;
                        ${({theme:e})=>e.rtl?`padding-left`:`padding-right`}: 0 !important;
                        transition: all 0.2s cubic-bezier(0.215, 0.61, 0.355, 1) 0s;
                        a{
                            ${({theme:e})=>e.rtl?`padding-right`:`padding-left`}: 36px !important;
                        }
                    }
                }
                .ant-menu-item{
                    display: flex;
                    align-items: center;
                    padding: 0 15px !important;
                    a{
                        width: 100%;
                        display: flex !important;
                        align-items: center;
                        .feather{
                            width: 16px;
                            color: ${({theme:e})=>e[e.mainContent][`menu-icon-color`]};
                        }
                        span{
                            ${({theme:e})=>e.rtl?`padding-right`:`padding-left`}: 20px;
                            display: inline-block;
                            color: ${({theme:e})=>e[`dark-color`]};
                        }
                    }
                    &.ant-menu-item-selected{
                        svg,
                        i{
                            color: ${({theme:e})=>e[e.mainContent][`menu-active`]};
                        }
                    }
                }


                &.ant-menu-inline-collapsed{
                    .ant-menu-submenu{
                        text-align: ${({theme:e})=>e.rtl?`right`:`left`};
                        .ant-menu-submenu-title{
                            padding: 0 20px;
                            justify-content: center;
                        }
                    }
                    .ant-menu-item{
                        padding: 0 20px !important;
                        justify-content: center;
                    }
                    .ant-menu-submenu, .ant-menu-item{
                        span{
                            display: none;
                        }
                    }
                }
            }
        }
    }
    @media only screen and (max-width: 1150px){
        .ant-layout-sider.ant-layout-sider-collapsed{
            ${({theme:e})=>e.rtl?`right`:`left`}: -80px !important;
        }

    }

    .ninjadash-main-layout{
        ${({theme:e})=>e.rtl?`margin-right`:`margin-left`}: ${({theme:e})=>e.topMenu?0:`280px`};
        margin-top: 64px;
        transition: 0.3s ease;

        @media only screen and (max-width: 1150px){
            ${({theme:e})=>e.rtl?`margin-right`:`margin-left`}: auto !important;
        }
        @media print {
            width: 100%;
            margin-left: 0;
            margin-right: 0;
        }
    }
    .admin-footer{
        background-color: ${({theme:e})=>e[e.mainContent][`white-background`]} !important;
        @media print {
            display: none;
        }
        .admin-footer__copyright{
            display: inline-block;
            width: 100%;
            font-weight: 500;
            color: ${({theme:e})=>e[e.mainContent][`gray-text`]};
            @media only screen and (max-width: 767px){
                text-align: center;
                margin-bottom: 10px;
            }
            a{
                display: inline-block;
                margin-left: 4px;
                font-weight: 500;
                color: ${({theme:e})=>e[e.mainContent][`menu-active`]};
            }
        }
        .admin-footer__links{
            margin: 0 -9px;
            text-align: ${({theme:e})=>e.rtl?`left`:`right`};
            @media only screen and (max-width: 767px){
                text-align: center;
            }
            a {
                margin: 0 9px;
                color: ${({theme:e})=>e[e.mainContent][`gray-text`]};
                &:hover{
                    color: ${({theme:e})=>e[`primary-color`]};
                }
                &:not(:last-child) {
                    ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 15px;
                }
            }
        }
    }
    /* Common Styles */
    .ant-radio-button-wrapper-checked:not() {
        &:not(.ant-radio-button-wrapper-disabled){
            background: ${({theme:e})=>e[e.mainContent].white};
            color: ${({theme:e})=>e[e.mainContent][`menu-active`]};
        }
    }
`;P(`div`,R)`
        ${({darkMode:e})=>e?`background: #272B41;`:`background: #fff`};
        width: 100%;
        position: fixed;
        margin-top: ${({hide:e})=>e?`0px`:`64px`};
        top: 9px;
        ${({theme:e})=>e.rtl?`right`:`left`}: 0;
        transition: .3s;
        opacity: ${({hide:e})=>+!e}
        z-index: ${({hide:e})=>e?-1:1}
        box-shadow: 0 2px 30px #9299b810;
			.ninjadash-nav-actions__author--name{
				display: none;
			}
			.ninjadash-nav-action-link{
				display: flex;
				align-items: center;
			}
			@media only screen and (max-width: 767px){
        padding: 10px 15px;
			}
			.ninjadash-nav-actions__searchbar{
					display: none !important;
			}
`,P(`div`,R)`
        ${({darkMode:e})=>e?`background: #272B41;`:`background: #fff`};
        width: 100%;
        position: fixed;
        margin-top: ${({hide:e})=>e?`0px`:`64px`};
        top: 0;
        ${({theme:e})=>e.rtl?`right`:`left`}: 0;
        transition: .3s;
        opacity: ${({hide:e})=>+!e}
        z-index: ${({hide:e})=>e?-1:999}
        box-shadow: 0 2px 30px #9299b810;
`,P(`div`,R)`
    background: #ddd;
    width: 200px;
    position: fixed;
    ${({theme:e})=>e.rtl?`left`:`right`}: 0;
    top: 50%;
    margin-top: -100px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    padding: 15px;
    border-radius: 5px;
    button{
        margin-top: 5px;
    }
`;var be=P(`div`,R)`
    .top-right-wrap{
        position: relative;
        float: ${({theme:e})=>e.rtl?`left`:`right`};
    }
    .search-toggle{
        display: flex;
        align-items: center;
        ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 10px;
        ${({theme:e})=>e.darkMode?`color: #A8AAB3;`:`color :#5A5F7D`};
        .feather-x{
            display: none;
        }
        .feather-search{
            display: flex;
        }
        &.active{
            .feather-search{
                display: none;
            }
            .feather-x{
                display: flex;
            }
        }
        svg,
        img{
            width: 20px;
        }
    }
    .topMenu-search-form{
        position: absolute;
        ${({theme:e})=>e.rtl?`left`:`right`}: 100%;
        ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 15px;
        top: 12px;
        background-color: #fff;
        border: 1px solid ${({theme:e})=>e[`border-color-normal`]};
        border-radius: 6px;
        height: 40px;
        width: 280px;
        display: none;
        &.show{
            display: block;
        }
        .search-icon{
            width: fit-content;
            line-height: 1;
            position: absolute;
            left: 15px;
            ${({theme:e})=>e.rtl?`right`:`left`}: 15px;
            top: 50%;
            transform: translateY(-50%);
            z-index: 9999;
        }
        i,
        svg{
            width: 18px;
            fill: ${({theme:e})=>e.darkMode?`color: #A8AAB3;`:`color:# 9299b8`};
        }
        svg{
            fill: ${({theme:e})=>e.darkMode?`color: #A8AAB3;`:`color:# 9299b8`};
        }
        form{
            height: auto;
            display: flex;
            align-items: center;
        }
        input{
            position: relative;
            border-radius: 6px;
            width: 100%;
            border: 0 none;
            height: 38px;
            padding-left: 40px;
            z-index: 999;
            ${({theme:e})=>e.rtl?`padding-right`:`padding-left`}: 40px;
            &:focus{
                border: 0 none;
                box-shadow: 0 0;
                outline: none;
            }
        }
    }
`,xe=P(`div`,R)`
.ninjadash-top-menu{
    ul{
        margin-bottom: 0;
        li{
            display: inline-block;
            position: relative;
            ${({theme:e})=>e.rtl?`padding-left`:`padding-right`}: 14px;
            @media only screen and (max-width: 1024px){
                ${({theme:e})=>e.rtl?`padding-left`:`padding-right`}: 10px;
            }
            &:not(:last-child){
                ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 34px;
                @media only screen and (max-width: 1399px){
                    ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 30px;
                }
                @media only screen and (max-width: 1199px){
                    ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 26px;
                }
                @media only screen and (max-width: 1024px){
                    ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 16px;
                }
            }
            .parent.active{
                color: ${({theme:e})=>e[e.mainContent][`menu-active`]};
            }
            &.has-subMenu{
                >a{
                    position: relative;
                    &:before{
                        position: absolute;
                        ${({theme:e})=>e.rtl?`left`:`right`}: -14px;
                        top: 50%;
                        transform: translateY(-50%);
                        font-family: "FontAwesome";
                        content: '\f107';
                        line-height: 1;
                        color: ${({theme:e})=>e[e.mainContent][`light-text`]};
                    }
                    &.active{
                        &:before{
                            color: ${({theme:e})=>e[e.mainContent][`menu-active`]};
                        }
                    }
                }
            }
            &.has-subMenu-left{
                >a{
                    position: relative;
                    &:before{
                        position: absolute;
                        ${({theme:e})=>e.rtl?`left`:`right`}: 30px;
                        top: 50%;
                        transform: translateY(-50%);
                        font-family: "FontAwesome";
                        content: '\f105';
                        line-height: 1;
                        color: ${({theme:e})=>e[e.mainContent][`light-text`]};
                    }
                }
            }
            &:hover{
                >.subMenu{
                    top: 70px;
                    opacity: 1;
                    visibility: visible;
                    @media only screen and (max-width: 1399px){
                        top: 40px;
                    }
                }
            }
            >a{
                padding: 24px 0;
                line-height: 1.5;
                @media only screen and (max-width: 1599px){
                    padding: 6px 0;
                }
            }
            a{
                display: flex;
                align-items: center;
                font-weight: 500;
                color: ${({theme:e})=>e[e.mainContent][`light-text`]};
                &.active{
                    color: ${({theme:e})=>e[e.mainContent][`light-text`]};
                }
                svg,
                img,
                i{
                    margin-right: 14px;
                    width: 16px;
                }
            }
            >ul{
                li{
                    display: block;
                    position: relative;
                    ${({theme:e})=>e.rtl?`padding-left`:`padding-right`}: 0;
                    ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 0 !important;
                    a{
                        font-weight: 400;
                        padding: 0 30px;
                        line-height: 3;
                        color: #868EAE;
                        transition: .3s;
                        &:hover,
                        &[aria-current="page"]{
                            color: ${({theme:e})=>e[e.mainContent][`menu-active`]};
                            background-color: ${({theme:e})=>e[e.mainContent][`menu-active`]}06;
                            ${({theme:e})=>e.rtl?`padding-right`:`padding-left`}: 40px;
                        }
                    }
                    &:hover{
                        .subMenu{
                            top: 0;
                            ${({theme:e})=>e.rtl?`right`:`left`}: 250px;
                            @media only screen and (max-width: 1300px){
                                ${({theme:e})=>e.rtl?`right`:`left`}: 180px;
                            }
                        }
                    }
                }
            }
        }
    }
    .subMenu{
        width: 250px;
        background: ${({theme:e})=>e[e.mainContent][`white-background`]};
        border-radius: 6px;
        position: absolute;
        ${({theme:e})=>e.rtl?`right`:`left`}: 0;
        top: 80px;
        padding: 12px 0;
        visibility: hidden;
        opacity: 0;
        transition: 0.3s;
        z-index: 98;
        box-shadow: 0px 15px 40px 0px rgba(82, 63, 105, 0.15);
        @media only screen and (max-width: 1300px){
            width: 180px;
        }
        .subMenu{
            width: 250px;
            background:${({theme:e})=>e[e.mainContent][`white-background`]};
            position: absolute;
            ${({theme:e})=>e.rtl?`right`:`left`}: 250px;
            top: 0px;
            padding: 12px 0;
            visibility: hidden;
            opacity: 0;
            transition: 0.3s;
            z-index: 98;
            box-shadow: 0px 15px 40px 0px rgba(82, 63, 105, 0.15);
            @media only screen and (max-width: 1300px){
                width: 200px;
                ${({theme:e})=>e.rtl?`right`:`left`}: 180px;
            }
        }
    }
}
.ninjadash-top-menu{
    >ul{
        display: flex;
        flex-wrap: wrap;
    }
}
// Mega Menu
.ninjadash-top-menu{
    >ul{
        >li{
            &:hover{
                .megaMenu-wrapper{
                    opacity: 1;
                    visibility: visible;
                    z-index: 99;
                }
            }
            &.mega-item{
                position: static;
            }
            .sDash_menu-item-icon{
                line-height: .6;
            }
            .megaMenu-wrapper{
                display: flex;
                position: absolute;
                text-align: ${({theme:e})=>e.rtl?`right`:`left`}
                ${({theme:e})=>e.rtl?`right`:`left`}: 0;
                top: 100%;
                overflow: hidden;
                z-index: -1;
                padding: 16px 0;
                box-shadow: 0px 15px 40px 0px rgba(82, 63, 105, 0.15);
                border-radius: 0 0 6px 6px;
                opacity: 0;
                visibility: hidden;
                transition: .4s;
                background-color: ${({theme:e})=>e[e.mainContent][`white-background`]};
                &.megaMenu-small{
                    width: 590px;
                    >li{
                        flex: 0 0 33.3333%;
                    }
                    ul{
                        li{
                            &:after{
                                display: none;
                            }
                            >a{
                                padding: 0 35px;
                                position: relative
                                &:after{
                                    width: 5px;
                                    height: 5px;
                                    border-radius: 50%;
                                    position: absolute;
                                    ${({theme:e})=>e.rtl?`right`:`left`}: 20px;
                                    top: 50%;
                                    transform: translateY(-50%);
                                    background-color: #C6D0DC;
                                    content: '';
                                    transition: .3s;
                                }
                                &:hover,
                                &[aria-current="page"]{
                                    ${({theme:e})=>e.rtl?`padding-right`:`padding-left`}: 35px;
                                    color: ${({theme:e})=>e[`primary-color`]};
                                    &:after{
                                        background-color: ${({theme:e})=>e[`primary-color`]};;
                                    }
                                }
                            }
                        }
                    }
                }
                &.megaMenu-wide{
                    width: 1000px;
                    padding: 5px 0 18px;
                    @media only screen and (max-width: 1599px){
                        width: 800px;
                    }
                    @media only screen and (max-width: 1399px){
                        width: 700px;
                    }
                    >li{
                        position: relative;
                        flex: 0 0 25%;
                        .mega-title{
                            position: relative;
                            font-size: 14px;
                            font-weight: 500;
                            padding-left: 35px;
                            ${({theme:e})=>e.rtl?`padding-right`:`padding-left`}: 45px;
                            color: ${({theme:e})=>e[e.mainContent][`dark-text`]};
                            &:after{
                                position: absolute;
                                height: 5px;
                                width: 5px;
                                border-radius: 50%;
                                ${({theme:e})=>e.rtl?`right`:`left`}: 30px;
                                top: 50%;
                                transform: translateY(-50%);
                                background-color: #C6D0DC;
                                content: '';
                            }
                        }
                    }
                }
                ul{
                    li{
                        position: relative;
                        &:hover{
                            >a{
                                padding-left: 35px;
                            }
                            &:after{
                                opacity: 1;
                                visibility: visible;
                            }
                        }
                        >a{
                            line-height: 3;
                            color: #868EAE;
                            font-weight: 400;
                            transition: .3s;
                        }

                        &:after{
                            width: 6px;
                            height: 1px;
                            border-radius: 50%;
                            position: absolute;
                            ${({theme:e})=>e.rtl?`right`:`left`}: 20px;
                            top: 50%;
                            transform: translateY(-50%);
                            background-color: ${({theme:e})=>e[e.mainContent][`gray-light-text`]};
                            content: '';
                            transition: .3s;
                            opacity: 0;
                            visibility: hidden;
                        }
                    }
                }
            }
        }
    }
}
`;P.aside`
  width: 100%;
  height: 100vh;
  position: relative;
  background-image: url(/assets/img/auth/BG.png);
  background-repeat: no-repeat;
  background-attachment: fixed;
  background-position: left top;
  @media only screen and (max-width: 767px){
    height: 100%;
  }
  .topShape {
    position: absolute;
    top: 0;
    right: 0;
    width: 400px;
  }
  .bottomShape {
    position: absolute;
    bottom: 0;
    left: 0;
    //width: 400px;
  }
  .auth-side-content{
    @media only screen and (max-width: 991px){
      h1{
        font-size: 20px;
      }
    }
    @media only screen and (max-width: 767px){
      h1{
        font-size: 24px;
        margin-bottom: 28px;
      }
    }
  }
`,P.div`
    padding: 100px;
    @media only screen and (max-width: 1599px){
        padding: 50px;
    }
    @media only screen and (max-width: 991px){
        padding: 20px;
    }
    @media only screen and (max-width: 767px){
        text-align: center;
    }
    .auth-content-figure{
        @media only screen and (max-width: 1199px){
            max-width: 420px;
        }
        @media only screen and (max-width: 991px){
            max-width: 100%;
        }
    }
`,P.div`
  position: relative;
  padding: 120px 0;
  background-position: top;
  background-repeat: no-repeat;
  @media only screen and (max-width: 1399px){
    padding: 80px 0;
  }
  .ninjadash-authentication-brand{
    text-align: center;
  }
`;var Se=[`aria-label`],Ce={class:`hqm-panel__header`},we={class:`hqm-panel__body`},Te=L(m({__name:`HeaderQuickModal`,props:{modelValue:{type:Boolean},ariaLabel:{}},emits:[`update:modelValue`,`opened`],setup(e,{emit:t}){let n=e,i=t;function a(){i(`update:modelValue`,!1)}return d(()=>n.modelValue,e=>{document.body.style.overflow=e?`hidden`:``,e&&i(`opened`)}),(t,n)=>(r(),T(E,{to:`body`},[A(ee,{name:`hqm-fade`},{default:f(()=>[e.modelValue?(r(),v(`div`,{key:0,class:`hqm-backdrop`,onClick:N(a,[`self`]),onKeydown:M(a,[`esc`])},[_(`section`,{class:`hqm-panel`,role:`dialog`,"aria-modal":`true`,"aria-label":e.ariaLabel},[_(`div`,Ce,[c(t.$slots,`header`,{close:a},void 0,!0)]),_(`div`,we,[c(t.$slots,`default`,{close:a},void 0,!0)])],8,Se)],32)):C(``,!0)]),_:3})]))}}),[[`__scopeId`,`data-v-5743a0b5`]]),Ee={class:`ps-input-wrap`},De=[`onClick`],Oe={class:`ps-results`},ke={key:0,class:`ps-state`},Ae={key:1,class:`ps-state`},je={key:2,class:`ps-state`},Me={key:3,class:`ps-state`},Ne=[`onClick`],Pe={class:`ps-result-icon`},Fe={class:`ps-result-content`},Ie={class:`ps-result-title`},Le={class:`ps-result-subtitle`},Re=L(m({__name:`PortalSearch`,setup(e){let i=p(!1),a=p(``),c=p([]),l=p(!1),u=p(!1),m=p(null),h=F(),g=null,b={ONLINE:{label:`Up`,class:`ok`},OFFLINE:{label:`Down`,class:`down`},DISABLED:{label:`Disabled`,class:`muted`},ERROR:{label:`Error`,class:`down`},UNKNOWN:{label:`Unknown`,class:`muted`}},x={device:`server-network`,interface:`wifi-router`,description:`comment-alt-message`,agreement:`tag-alt`,ont_ident:`wifi`,fdb_history:`history`,tags:`tag-alt`};function w(e){return e.type===`ont_ident`||e.type===`fdb_history`||e.type===`tags`?e.data.interface:e.data}function T(e){let t=e.data;switch(e.type){case`device`:return`Device: ${t.name||t.ip}`;case`description`:return`Description: ${t.description||`—`}`;case`agreement`:return`Agreement: ${t.agreement||`—`}`;case`interface`:return`Interface: ${t.name||`—`}`;case`ont_ident`:return`ONT ident: ${t.ident||t.serial||t.value||`—`}`;case`fdb_history`:return`MAC: ${t.mac_address||`—`}`;case`tags`:return`Tag: ${t.tags||`—`}`;default:return`Result`}}function E(e){let t=w(e);if(e.type===`device`){let t=[D(e).model?.vendor,D(e).model?.model||D(e).model?.name].filter(Boolean).join(` `);return t?`Model: ${t}`:D(e).ip||``}let n=t?.device,r=n?` on device ${n.name||n.ip} (${n.ip||``})`:``;return`${e.type===`fdb_history`&&e.data.vlan_id!=null?`VLAN ${e.data.vlan_id} · `:``}Interface: ${t?.name||`—`}${r}`}function D(e){return e.data}function k(e){let t=w(e);return t?.status?b[t.status]:null}function j(e){let t=e.type===`device`?e.data:w(e)?.device,n=e.type===`device`?e.data.id:w(e)?.device_id||t?.id;n&&h.push({name:`device-detail`,params:{id:n}}),i.value=!1}async function M(){let e=a.value.trim();if(e.length<3){c.value=[],u.value=!1;return}l.value=!0;try{let{data:t}=await de.get(`/portal/search`,{query:e});c.value=t.data||[]}catch{c.value=[]}finally{l.value=!1,u.value=!0}}d(a,()=>{g&&clearTimeout(g),g=setTimeout(M,350)});function ee(){a.value=``,c.value=[],u.value=!1,setTimeout(()=>m.value?.focus(),50)}return(e,d)=>{let p=n(`unicon`),h=ce;return r(),v(y,null,[_(`a`,{href:`#`,class:`header-launcher-btn`,title:`Search`,onClick:d[0]||=N(e=>i.value=!0,[`prevent`])},[A(p,{name:`search`})]),A(Te,{modelValue:i.value,"onUpdate:modelValue":d[2]||=e=>i.value=e,ariaLabel:`Search`,onOpened:ee},{header:f(({close:e})=>[_(`div`,Ee,[A(p,{name:`search`}),s(_(`input`,{ref_key:`inputRef`,ref:m,"onUpdate:modelValue":d[1]||=e=>a.value=e,type:`search`,class:`ps-input`,autocomplete:`off`,placeholder:`Searching...`},null,512),[[te,a.value]]),_(`button`,{type:`button`,class:`ps-close`,"aria-label":`Close`,onClick:e},[A(p,{name:`times`})],8,De)])]),default:f(()=>[_(`div`,Oe,[l.value?(r(),v(`div`,ke,[A(h,{size:`small`}),d[3]||=_(`span`,null,`Searching…`,-1)])):a.value.trim().length>0&&a.value.trim().length<3?(r(),v(`div`,Ae,[A(p,{name:`keyboard`}),_(`span`,null,`Type more `+o(3-a.value.trim().length)+` symbols for start searching...`,1)])):u.value&&c.value.length===0?(r(),v(`div`,je,[A(p,{name:`search-alt`}),_(`span`,null,`No matches for "`+o(a.value)+`"`,1)])):a.value?C(``,!0):(r(),v(`div`,Me,[A(p,{name:`keyboard`}),d[4]||=_(`span`,null,`Type more 3 symbols for start searching...`,-1)])),(r(!0),v(y,null,t(c.value,(e,t)=>(r(),v(`button`,{key:t,type:`button`,class:`ps-result-row`,onClick:t=>j(e)},[_(`span`,Pe,[A(p,{name:x[e.type]||`search`},null,8,[`name`])]),_(`span`,Fe,[_(`span`,Ie,[O(o(T(e))+` `,1),k(e)?(r(),v(`span`,{key:0,class:S([`ps-badge`,k(e).class])},o(k(e).label),3)):C(``,!0)]),_(`span`,Le,o(E(e)),1)])],8,Ne))),128))])]),_:1},8,[`modelValue`])],64)}}}),[[`__scopeId`,`data-v-a006f82e`]]),ze={class:`no-header`},Be=[`onClick`],Ve={class:`no-results`},He={key:0,class:`no-state`},Ue={key:1,class:`no-state error`},We={key:2,class:`no-state`},Ge=[`onClick`],Ke={class:`no-result-icon`},qe={class:`no-result-content`},Je={class:`no-result-title`},Ye={class:`no-result-subtitle`},Xe={class:`no-result-distance`},Ze=500,Qe=L(m({__name:`NearbyObjects`,setup(e){let i=p(!1),a=p(`all`),c=p(!1),l=p(``),u=p([]),m=F(),h={device:`server-network`,interface:`wifi-router`,box:`box`};function g(e){let t=e.data;return e.type===`device`?t.name||t.ip||`Device`:e.type===`interface`?t.name||`Interface`:t.name||t.title||`Box`}function b(e){let t=e.data;if(e.type===`device`)return[t.model?.vendor,t.model?.model||t.model?.name].filter(Boolean).join(` `)||t.ip||``;if(e.type===`interface`){let e=t.device;return e?`on device ${e.name||e.ip}`:``}return t.description||``}function x(e){return e==null?``:e<1e3?`${Math.round(e)} m away`:`${(e/1e3).toFixed(2)} km away`}function S(e){let t=e.type===`device`?e.data.id:e.data.device_id||e.data.device?.id;t&&m.push({name:`device-detail`,params:{id:t}}),i.value=!1}function w(){if(l.value=``,!navigator.geolocation){l.value=`Geolocation is not supported by this browser`;return}c.value=!0,navigator.geolocation.getCurrentPosition(async e=>{try{let{data:t}=await de.get(`/portal/nearest-elements`,{lat:e.coords.latitude,lon:e.coords.longitude,distance:Ze,...a.value===`all`?{}:{filter:a.value}});u.value=t.data||[]}catch{l.value=`Could not load nearby objects — please try again.`}finally{c.value=!1}},()=>{l.value=`Location access denied`,c.value=!1},{enableHighAccuracy:!1,timeout:8e3})}d(a,()=>{i.value&&w()});function T(){u.value=[],l.value=``,w()}return(e,d)=>{let p=n(`unicon`),m=ce,E=n(`sdButton`);return r(),v(y,null,[_(`a`,{href:`#`,class:`header-launcher-btn`,title:`Nearby objects`,onClick:d[0]||=N(e=>i.value=!0,[`prevent`])},[A(p,{name:`crosshair`})]),A(Te,{modelValue:i.value,"onUpdate:modelValue":d[2]||=e=>i.value=e,ariaLabel:`Nearby objects`,onOpened:T},{header:f(({close:e})=>[_(`div`,ze,[A(p,{name:`crosshair`}),d[4]||=_(`span`,{class:`no-title`},`Nearby objects`,-1),s(_(`select`,{"onUpdate:modelValue":d[1]||=e=>a.value=e,class:`no-filter`,"aria-label":`Object type`},[...d[3]||=[_(`option`,{value:`all`},`All objects`,-1),_(`option`,{value:`device`},`Device`,-1),_(`option`,{value:`interface`},`Interface`,-1),_(`option`,{value:`box`},`Boxes`,-1)]],512),[[j,a.value]]),_(`button`,{type:`button`,class:`no-close`,"aria-label":`Close`,onClick:e},[A(p,{name:`times`})],8,Be)])]),default:f(()=>[_(`div`,Ve,[c.value?(r(),v(`div`,He,[A(m,{size:`small`}),d[5]||=_(`span`,null,`Locating…`,-1)])):l.value?(r(),v(`div`,Ue,[A(p,{name:`exclamation-circle`}),_(`span`,null,o(l.value),1),A(E,{size:`small`,type:`primary`,onClick:w},{default:f(()=>[...d[6]||=[O(`Retry`,-1)]]),_:1})])):u.value.length===0?(r(),v(`div`,We,[A(p,{name:`map-marker-alt`}),d[7]||=_(`span`,null,`No objects found nearby.`,-1)])):C(``,!0),(r(!0),v(y,null,t(u.value,(e,t)=>(r(),v(`button`,{key:t,type:`button`,class:`no-result-row`,onClick:t=>S(e)},[_(`span`,Ke,[A(p,{name:h[e.type]||`map-marker-alt`},null,8,[`name`])]),_(`span`,qe,[_(`span`,Je,o(g(e)),1),_(`span`,Ye,o(b(e)),1)]),_(`span`,Xe,o(x(e.distance_m)),1)],8,Ge))),128))])]),_:1},8,[`modelValue`])],64)}}}),[[`__scopeId`,`data-v-7f35a6d0`]]),$e=P(`div`,[`darkMode`])`
    display: flex;
    justify-content: flex-end;
    align-items: center;
    .ninjadash-nav-action-link{
        text-decoration: none;
        color: ${({theme:e})=>e[e.mainContent].secondary};
        box-shadow: none;
        padding: 0px 8px;
        img{
            vertical-align: unset;
        }
    }
    .ninjadash-nav-actions__searchbar{
        display: flex;
        align-items: center;
        @media only screen and (max-width: 767px){
            display: none;
        }
        svg,
        img{
            width: 16px;
            height: 16px;
					}
				svg{
					fill: ${({theme:e})=>e[e.mainContent][`light-text`]};
				}
        .ninjadash-searchbar{
            opacity: 0;
            visibility: visible;
            transition: .35s;
            position: relative;
            top: 3px;
            input{
                user-select: none;
                pointer-events: none;
            }
        }
        &.show{
            .ninjadash-searchbar{
                opacity: 1;
                visibility: visible;
                input{
                    user-select: all;
                    pointer-events: all;
                }
            }
            .ninjadash-search-icon{
                display: none;
            }
            .ninjadash-close-icon{
                display: block;
            }
        }
        .ninjadash-search-icon{
            svg{
                fill: ${({theme:e})=>e[`gray-color`]};
            }
        }
        .ninjadash-close-icon{
            display: none;
        }
        a{
            line-height: .8;
            position: relative;
            top: 4px;
        }
    }
    .ninjadash-searchbar{
        .ant-form-item{
            margin-bottom: 0;
            .ant-form-item-control-input{
                min-height: 30px;
                .ant-input{
                    padding: 5px;
                    border: 0 none;
                    &:focus{
                        outline: none;
                        box-shadow: 0 0;
                    }
                }
            }
        }
    }
    .ninjadash-nav-actions__item{
        .ant-badge{
            .ant-badge-dot{
                top: 4px;
                ${({theme:e})=>e.rtl?`left`:`right`}: 11px !important;
            }
        }
        &.ninjadash-nav-actions__message{
            .ant-badge{
                .ant-badge-dot{
                    background: ${({theme:e})=>e[e.mainContent].success};
                }
            }
        }
        svg{
            fill: ${({theme:e})=>e[e.mainContent][`gray-text`]};
        }
    }
    .ninjadash-nav-actions__message,
    .ninjadash-nav-actions__notification,
    .ninjadash-nav-actions__settings,
    .ninjadash-nav-actions__support,
    .ninjadash-nav-actions__flag-select,
    .ninjadash-nav-actions__language,
    .ninjadash-nav-actions__searchbar,
    .ninjadash-nav-actions__nav-author{
        display: flex;
        margin: 0 5px;
        span, a{
            display: block;
            line-height: normal;
        }
    }
    .ninjadash-nav-actions__nav-author{
        a.ant-dropdown-trigger{
            img{
                max-width: 20px;
            }
        }
    }

    .flag-select{
        padding-bottom: 0;
        .flag-select__option{
            margin: 0;
            img{
                top: 0;
            }
        }
        .flag-select__btn{
            line-height: 0;
            padding-right: 0;
            cursor: pointer;
        }
        .flag-select__btn:after{
            content: none;
        }
        .flag-select__options{
            width: 120px;
            padding-top: 0;
            margin: 0;
            right: 0;
            top: 30px;
            display: block;
            .flag-select__option{
                line-height: normal;
                display: block;
                padding: 5px 10px;
                span{
                    width: auto !important;
                    height: auto !important;
                    display: block;
                }
            }
        }
    }

    .flag-select {
        ul{
            width: 170px !important;
            padding: 12px 0;
            background: #fff;
            border: 0 none;
            box-shadow: 0 5px 30px ${({theme:e})=>e[`gray-solid`]}15;
            li{
                &:first-child{
                    margin-top: 12px;
                }
                &:hover{
                    background: ${({theme:e})=>e[`primary-color`]}05;
                }
                span{
                    display: flex !important;
                    align-items: center;
                    padding: 2px 10px;
                    img{
                        border-radius: 50%;
                    }
                    span{
                        font-weight: 500;
                        color: ${({theme:e})=>e[`gray-color`]};
                        padding: 0;
                        margin-left: 10px;
                    }
                }
            }
        }
    }
`;P.div`
    .setting-dropdown{
        max-width: 700px;
        padding: 4px 0;
        .setting-dropdown__single{
            align-items: flex-start;
            padding: 16px 20px;
            margin-bottom: 0;
            position: relative;
            &:after{
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                box-shadow: 0 5px 20px ${({theme:e})=>e[`gray-solid`]}15;
                z-index: 1;
                content: '';
                opacity: 0;
                visibility: hidden;
            }
            &:hover{
                &:after{
                    opacity: 1;
                    visibility: visible;
                }
            }
            h1{
                font-size: 15px;
                font-weight: 500;
                margin: -4px 0 2px;
				color: ${({theme:e})=>e[e.mainContent][`dark-text`]};
            }
            p{
                margin-bottom: 0;
                color: ${({theme:e})=>e[`gray-solid`]};
            }
            img{
                ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 16px;
                transform: ${({theme:e})=>e.rtl?`rotatey(180deg)`:`rotatey(0deg)`};
            }
            figcaption{
                text-align: ${({theme:e})=>e.rtl?`right`:`left`}
            }
        }
    }
`,P.div`
    .support-dropdown{
        padding: 10px 15px;
        text-align: ${({theme:e})=>e.rtl?`right`:`left`};
        ul{
            &:not(:last-child){
                margin-bottom: 16px;
            }
            h1{
                font-size: 14px;
                font-weight: 400;
                color: ${({theme:e})=>e[e.mainContent][`light-text`]};
            }
            li{
                a{
                    font-weight: 500;
                    padding: 4px 16px;
                    color: ${({theme:e})=>e[e.mainContent][`dark-text`]};
                    &:hover{
                        background: #fff;
                        color: ${({theme:e})=>e[`primary-color`]};
                    }
                }
            }
        }
    }
`,P.div`
    .user-dropdown{
        max-width: 280px;
        .user-dropdown__info{
            display: flex;
            align-items: flex-start;
            padding: 20px 25px;
            border-radius: 8px;
            margin-bottom: 12px;
            background: ${({theme:e})=>e[e.mainContent][`general-background`]};
            img{
                ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 15px;
            }
            figcaption{
                h1{
                    font-size: 14px;
                    margin-bottom: 2px;
                    color:  ${({theme:e})=>e[e.mainContent][`dark-text`]};
                }
                p{
                    margin-bottom: 0px;
                    font-size: 13px;
                    color: ${({theme:e})=>e[e.mainContent][`gray-text`]};
                }
            }
        }
        .user-dropdown__links{
            a{
                width: calc(100% + 30px);
                left: -15px;
                right: -15px;
                display: inline-flex;
                align-items: center;
                padding: 10px 12px;
                font-size: 14px;
                transition: .3s;
                color: ${({theme:e})=>e[e.mainContent][`gray-light-text`]};
                &:hover{
                    background: ${({theme:e})=>e[`primary-color`]}05;
                    color: ${({theme:e})=>e[e.mainContent][`menu-active`]};
                    ${({theme:e})=>e.rtl?`padding-right`:`padding-left`}: 22px;
                    svg{
                        fill: ${({theme:e})=>e[`primary-color`]};
                    }
                }
                svg{
                    width: 16px;
                    transform: ${({theme:e})=>e.rtl?`rotateY(180deg)`:`rotateY(0deg)`};
                    ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 14px;
					fill: ${({theme:e})=>e[e.mainContent][`light-text`]};
                }
            }
        }
        .user-dropdown__bottomAction{
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 500;
            text-align: center;
            position: relative;
            width: calc(100% + 30px);
            left: -15px;
            right: -15px;
            height: calc(100% + 15px);
            bottom: -15px;
            border-radius: 0 0 6px 6px;
            padding: 15px 0;
            background: ${({theme:e})=>e[e.mainContent][`general-background`]};
            color: ${({theme:e})=>e[e.mainContent][`light-text`]};
            svg{
                width: 15px;
                height: 15px;
                ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 8px;
            }
        }
    }
`;var et=P.div`
    .ninjadash-top-dropdown__title .title-text {
        ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 10px;
		color: ${({theme:e})=>e[e.mainContent][`dark-color`]};
    }
    .ninjadash-top-dropdown__content {
        figcaption{
            h1{
                color: ${({theme:e})=>e[e.mainContent][`dark-color`]};
            }
            .ninjadash-top-dropdownText{
                min-width: 216px;
                ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 15px;
            }
            span{
                ${({theme:e})=>e.rtl?`padding-right`:`padding-left`}: 0;
            }
        }
        .notification-icon{
            width: 39.2px;
            height: 32px;
            ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 15px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            &.bg-primary{
                background: ${({theme:e})=>e[`primary-color`]}15;
                svg{
                    fill: ${({theme:e})=>e[`primary-color`]};
                }
            }
            &.bg-secondary{
                background: ${({theme:e})=>e[`secondary-color`]}15;
                svg{
                    fill: ${({theme:e})=>e[`secondary-color`]};
                }
            }
            &.bg-danger{
                background: rgba(255, 77, 79, 0.12);
                svg{
                    fill: #e5484d;
                }
            }
            &.bg-warning{
                background: rgba(250, 173, 20, 0.15);
                svg{
                    fill: #d48806;
                }
            }
            svg{
                width: 18px;
                height: 18px;
            }
        }
        .notification-content{
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
    }

    .notification-text h1 {
        font-size: 14px;
        font-weight: 400;
        color: #5A5F7D;
        margin-bottom: 4px;
    }

    .notification-text h1 span {
        color: #5F63F2;
        font-weight: 500;
        ${({theme:e})=>e.rtl?`padding-right`:`padding-left`}: 0;
    }

    .notification-text p {
        font-size: 12px;
        color: #ADB4D2;
        margin-bottom: 0;
        text-align: ${({theme:e})=>e.rtl?`right`:`left`}
    }
`;P.span`
    i, svg, img {
        ${({theme:e})=>e.rtl?`margin-left`:`margin-right`}: 8px;
    }
`;function z(e){return getComputedStyle(e)}function B(e,t){for(var n in t){var r=t[n];typeof r==`number`&&(r+=`px`),e.style[n]=r}return e}function V(e){var t=document.createElement(`div`);return t.className=e,t}var tt=typeof Element<`u`&&(Element.prototype.matches||Element.prototype.webkitMatchesSelector||Element.prototype.mozMatchesSelector||Element.prototype.msMatchesSelector);function H(e,t){if(!tt)throw Error(`No element matching method supported`);return tt.call(e,t)}function U(e){e.remove?e.remove():e.parentNode&&e.parentNode.removeChild(e)}function nt(e,t){return Array.prototype.filter.call(e.children,function(e){return H(e,t)})}var W={main:`ps`,rtl:`ps__rtl`,element:{thumb:function(e){return`ps__thumb-`+e},rail:function(e){return`ps__rail-`+e},consuming:`ps__child--consume`},state:{focus:`ps--focus`,clicking:`ps--clicking`,active:function(e){return`ps--active-`+e},scrolling:function(e){return`ps--scrolling-`+e}}},rt={x:null,y:null};function it(e,t){var n=e.element.classList,r=W.state.scrolling(t);n.contains(r)?clearTimeout(rt[t]):n.add(r)}function at(e,t){rt[t]=setTimeout(function(){return e.isAlive&&e.element.classList.remove(W.state.scrolling(t))},e.settings.scrollingThreshold)}function ot(e,t){it(e,t),at(e,t)}var G=function(e){this.element=e,this.handlers={}},st={isEmpty:{configurable:!0}};G.prototype.bind=function(e,t){this.handlers[e]===void 0&&(this.handlers[e]=[]),this.handlers[e].push(t),this.element.addEventListener(e,t,!1)},G.prototype.unbind=function(e,t){var n=this;this.handlers[e]=this.handlers[e].filter(function(r){return t&&r!==t?!0:(n.element.removeEventListener(e,r,!1),!1)})},G.prototype.unbindAll=function(){for(var e in this.handlers)this.unbind(e)},st.isEmpty.get=function(){var e=this;return Object.keys(this.handlers).every(function(t){return e.handlers[t].length===0})},Object.defineProperties(G.prototype,st);var K=function(){this.eventElements=[]};K.prototype.eventElement=function(e){var t=this.eventElements.filter(function(t){return t.element===e})[0];return t||(t=new G(e),this.eventElements.push(t)),t},K.prototype.bind=function(e,t,n){this.eventElement(e).bind(t,n)},K.prototype.unbind=function(e,t,n){var r=this.eventElement(e);r.unbind(t,n),r.isEmpty&&this.eventElements.splice(this.eventElements.indexOf(r),1)},K.prototype.unbindAll=function(){this.eventElements.forEach(function(e){return e.unbindAll()}),this.eventElements=[]},K.prototype.once=function(e,t,n){var r=this.eventElement(e),i=function(e){r.unbind(t,i),n(e)};r.bind(t,i)};function q(e){if(typeof window.CustomEvent==`function`)return new CustomEvent(e);var t=document.createEvent(`CustomEvent`);return t.initCustomEvent(e,!1,!1,void 0),t}function J(e,t,n,r,i){r===void 0&&(r=!0),i===void 0&&(i=!1);var a;if(t===`top`)a=[`contentHeight`,`containerHeight`,`scrollTop`,`y`,`up`,`down`];else if(t===`left`)a=[`contentWidth`,`containerWidth`,`scrollLeft`,`x`,`left`,`right`];else throw Error(`A proper axis should be provided`);ct(e,n,a,r,i)}function ct(e,t,n,r,i){var a=n[0],o=n[1],s=n[2],c=n[3],l=n[4],u=n[5];r===void 0&&(r=!0),i===void 0&&(i=!1);var d=e.element;e.reach[c]=null,d[s]<1&&(e.reach[c]=`start`),d[s]>e[a]-e[o]-1&&(e.reach[c]=`end`),t&&(d.dispatchEvent(q(`ps-scroll-`+c)),t<0?d.dispatchEvent(q(`ps-scroll-`+l)):t>0&&d.dispatchEvent(q(`ps-scroll-`+u)),r&&ot(e,c)),e.reach[c]&&(t||i)&&d.dispatchEvent(q(`ps-`+c+`-reach-`+e.reach[c]))}function Y(e){return parseInt(e,10)||0}function lt(e){return H(e,`input,[contenteditable]`)||H(e,`select,[contenteditable]`)||H(e,`textarea,[contenteditable]`)||H(e,`button,[contenteditable]`)}function ut(e){var t=z(e);return Y(t.width)+Y(t.paddingLeft)+Y(t.paddingRight)+Y(t.borderLeftWidth)+Y(t.borderRightWidth)}var X={isWebKit:typeof document<`u`&&`WebkitAppearance`in document.documentElement.style,supportsTouch:typeof window<`u`&&(`ontouchstart`in window||`maxTouchPoints`in window.navigator&&window.navigator.maxTouchPoints>0||window.DocumentTouch&&document instanceof window.DocumentTouch),supportsIePointer:typeof navigator<`u`&&navigator.msMaxTouchPoints,isChrome:typeof navigator<`u`&&/Chrome/i.test(navigator&&navigator.userAgent)};function Z(e){var t=e.element,n=Math.floor(t.scrollTop),r=t.getBoundingClientRect();e.containerWidth=Math.floor(r.width),e.containerHeight=Math.floor(r.height),e.contentWidth=t.scrollWidth,e.contentHeight=t.scrollHeight,t.contains(e.scrollbarXRail)||(nt(t,W.element.rail(`x`)).forEach(function(e){return U(e)}),t.appendChild(e.scrollbarXRail)),t.contains(e.scrollbarYRail)||(nt(t,W.element.rail(`y`)).forEach(function(e){return U(e)}),t.appendChild(e.scrollbarYRail)),!e.settings.suppressScrollX&&e.containerWidth+e.settings.scrollXMarginOffset<e.contentWidth?(e.scrollbarXActive=!0,e.railXWidth=e.containerWidth-e.railXMarginWidth,e.railXRatio=e.containerWidth/e.railXWidth,e.scrollbarXWidth=dt(e,Y(e.railXWidth*e.containerWidth/e.contentWidth)),e.scrollbarXLeft=Y((e.negativeScrollAdjustment+t.scrollLeft)*(e.railXWidth-e.scrollbarXWidth)/(e.contentWidth-e.containerWidth))):e.scrollbarXActive=!1,!e.settings.suppressScrollY&&e.containerHeight+e.settings.scrollYMarginOffset<e.contentHeight?(e.scrollbarYActive=!0,e.railYHeight=e.containerHeight-e.railYMarginHeight,e.railYRatio=e.containerHeight/e.railYHeight,e.scrollbarYHeight=dt(e,Y(e.railYHeight*e.containerHeight/e.contentHeight)),e.scrollbarYTop=Y(n*(e.railYHeight-e.scrollbarYHeight)/(e.contentHeight-e.containerHeight))):e.scrollbarYActive=!1,e.scrollbarXLeft>=e.railXWidth-e.scrollbarXWidth&&(e.scrollbarXLeft=e.railXWidth-e.scrollbarXWidth),e.scrollbarYTop>=e.railYHeight-e.scrollbarYHeight&&(e.scrollbarYTop=e.railYHeight-e.scrollbarYHeight),ft(t,e),e.scrollbarXActive?t.classList.add(W.state.active(`x`)):(t.classList.remove(W.state.active(`x`)),e.scrollbarXWidth=0,e.scrollbarXLeft=0,t.scrollLeft=e.isRtl===!0?e.contentWidth:0),e.scrollbarYActive?t.classList.add(W.state.active(`y`)):(t.classList.remove(W.state.active(`y`)),e.scrollbarYHeight=0,e.scrollbarYTop=0,t.scrollTop=0)}function dt(e,t){return e.settings.minScrollbarLength&&(t=Math.max(t,e.settings.minScrollbarLength)),e.settings.maxScrollbarLength&&(t=Math.min(t,e.settings.maxScrollbarLength)),t}function ft(e,t){var n={width:t.railXWidth},r=Math.floor(e.scrollTop);n.left=t.isRtl?t.negativeScrollAdjustment+e.scrollLeft+t.containerWidth-t.contentWidth:e.scrollLeft,t.isScrollbarXUsingBottom?n.bottom=t.scrollbarXBottom-r:n.top=t.scrollbarXTop+r,B(t.scrollbarXRail,n);var i={top:r,height:t.railYHeight};t.isScrollbarYUsingRight?i.right=t.isRtl?t.contentWidth-(t.negativeScrollAdjustment+e.scrollLeft)-t.scrollbarYRight-t.scrollbarYOuterWidth-9:t.scrollbarYRight-e.scrollLeft:i.left=t.isRtl?t.negativeScrollAdjustment+e.scrollLeft+t.containerWidth*2-t.contentWidth-t.scrollbarYLeft-t.scrollbarYOuterWidth:t.scrollbarYLeft+e.scrollLeft,B(t.scrollbarYRail,i),B(t.scrollbarX,{left:t.scrollbarXLeft,width:t.scrollbarXWidth-t.railBorderXWidth}),B(t.scrollbarY,{top:t.scrollbarYTop,height:t.scrollbarYHeight-t.railBorderYWidth})}function pt(e){e.event.bind(e.scrollbarY,`mousedown`,function(e){return e.stopPropagation()}),e.event.bind(e.scrollbarYRail,`mousedown`,function(t){var n=t.pageY-window.pageYOffset-e.scrollbarYRail.getBoundingClientRect().top>e.scrollbarYTop?1:-1;e.element.scrollTop+=n*e.containerHeight,Z(e),t.stopPropagation()}),e.event.bind(e.scrollbarX,`mousedown`,function(e){return e.stopPropagation()}),e.event.bind(e.scrollbarXRail,`mousedown`,function(t){var n=t.pageX-window.pageXOffset-e.scrollbarXRail.getBoundingClientRect().left>e.scrollbarXLeft?1:-1;e.element.scrollLeft+=n*e.containerWidth,Z(e),t.stopPropagation()})}var Q=null;function mt(e){ht(e,[`containerHeight`,`contentHeight`,`pageY`,`railYHeight`,`scrollbarY`,`scrollbarYHeight`,`scrollTop`,`y`,`scrollbarYRail`]),ht(e,[`containerWidth`,`contentWidth`,`pageX`,`railXWidth`,`scrollbarX`,`scrollbarXWidth`,`scrollLeft`,`x`,`scrollbarXRail`])}function ht(e,t){var n=t[0],r=t[1],i=t[2],a=t[3],o=t[4],s=t[5],c=t[6],l=t[7],u=t[8],d=e.element,f=null,p=null,m=null;function h(t){t.touches&&t.touches[0]&&(t[i]=t.touches[0][`page`+l.toUpperCase()]),Q===o&&(d[c]=f+m*(t[i]-p),it(e,l),Z(e),t.stopPropagation(),t.preventDefault())}function g(){at(e,l),e[u].classList.remove(W.state.clicking),document.removeEventListener(`mousemove`,h),document.removeEventListener(`mouseup`,g),document.removeEventListener(`touchmove`,h),document.removeEventListener(`touchend`,g),Q=null}function _(t){Q===null&&(Q=o,f=d[c],t.touches&&(t[i]=t.touches[0][`page`+l.toUpperCase()]),p=t[i],m=(e[r]-e[n])/(e[a]-e[s]),t.touches?(document.addEventListener(`touchmove`,h,{passive:!1}),document.addEventListener(`touchend`,g)):(document.addEventListener(`mousemove`,h),document.addEventListener(`mouseup`,g)),e[u].classList.add(W.state.clicking)),t.stopPropagation(),t.cancelable&&t.preventDefault()}e[o].addEventListener(`mousedown`,_),e[o].addEventListener(`touchstart`,_)}function gt(e){var t=e.element,n=function(){return H(t,`:hover`)},r=function(){return H(e.scrollbarX,`:focus`)||H(e.scrollbarY,`:focus`)};function i(n,r){var i=Math.floor(t.scrollTop);if(n===0){if(!e.scrollbarYActive)return!1;if(i===0&&r>0||i>=e.contentHeight-e.containerHeight&&r<0)return!e.settings.wheelPropagation}var a=t.scrollLeft;if(r===0){if(!e.scrollbarXActive)return!1;if(a===0&&n<0||a>=e.contentWidth-e.containerWidth&&n>0)return!e.settings.wheelPropagation}return!0}e.event.bind(e.ownerDocument,`keydown`,function(a){if(!(a.isDefaultPrevented&&a.isDefaultPrevented()||a.defaultPrevented)&&(n()||r())){var o=document.activeElement?document.activeElement:e.ownerDocument.activeElement;if(o){if(o.tagName===`IFRAME`)o=o.contentDocument.activeElement;else for(;o.shadowRoot;)o=o.shadowRoot.activeElement;if(lt(o))return}var s=0,c=0;switch(a.which){case 37:s=a.metaKey?-e.contentWidth:a.altKey?-e.containerWidth:-30;break;case 38:c=a.metaKey?e.contentHeight:a.altKey?e.containerHeight:30;break;case 39:s=a.metaKey?e.contentWidth:a.altKey?e.containerWidth:30;break;case 40:c=a.metaKey?-e.contentHeight:a.altKey?-e.containerHeight:-30;break;case 32:c=a.shiftKey?e.containerHeight:-e.containerHeight;break;case 33:c=e.containerHeight;break;case 34:c=-e.containerHeight;break;case 36:c=e.contentHeight;break;case 35:c=-e.contentHeight;break;default:return}e.settings.suppressScrollX&&s!==0||e.settings.suppressScrollY&&c!==0||(t.scrollTop-=c,t.scrollLeft+=s,Z(e),i(s,c)&&a.preventDefault())}})}function _t(e){var t=e.element;function n(n,r){var i=Math.floor(t.scrollTop),a=t.scrollTop===0,o=i+t.offsetHeight===t.scrollHeight,s=t.scrollLeft===0,c=t.scrollLeft+t.offsetWidth===t.scrollWidth;return!(Math.abs(r)>Math.abs(n)?a||o:s||c)||!e.settings.wheelPropagation}function r(e){var t=e.deltaX,n=-1*e.deltaY;return(t===void 0||n===void 0)&&(t=-1*e.wheelDeltaX/6,n=e.wheelDeltaY/6),e.deltaMode&&e.deltaMode===1&&(t*=10,n*=10),t!==t&&n!==n&&(t=0,n=e.wheelDelta),e.shiftKey?[-n,-t]:[t,n]}function i(e,n,r){if(!X.isWebKit&&t.querySelector(`select:focus`))return!0;if(!t.contains(e))return!1;for(var i=e;i&&i!==t;){if(i.classList.contains(W.element.consuming))return!0;var a=z(i);if(r&&a.overflowY.match(/(scroll|auto)/)){var o=i.scrollHeight-i.clientHeight;if(o>0&&(i.scrollTop>0&&r<0||i.scrollTop<o&&r>0))return!0}if(n&&a.overflowX.match(/(scroll|auto)/)){var s=i.scrollWidth-i.clientWidth;if(s>0&&(i.scrollLeft>0&&n<0||i.scrollLeft<s&&n>0))return!0}i=i.parentNode}return!1}function a(a){var o=r(a),s=o[0],c=o[1];if(!i(a.target,s,c)){var l=!1;e.settings.useBothWheelAxes?e.scrollbarYActive&&!e.scrollbarXActive?(c?t.scrollTop-=c*e.settings.wheelSpeed:t.scrollTop+=s*e.settings.wheelSpeed,l=!0):e.scrollbarXActive&&!e.scrollbarYActive&&(s?t.scrollLeft+=s*e.settings.wheelSpeed:t.scrollLeft-=c*e.settings.wheelSpeed,l=!0):(t.scrollTop-=c*e.settings.wheelSpeed,t.scrollLeft+=s*e.settings.wheelSpeed),Z(e),l||=n(s,c),l&&!a.ctrlKey&&(a.stopPropagation(),a.preventDefault())}}window.onwheel===void 0?window.onmousewheel!==void 0&&e.event.bind(t,`mousewheel`,a):e.event.bind(t,`wheel`,a)}function vt(e){if(!X.supportsTouch&&!X.supportsIePointer)return;var t=e.element,n={startOffset:{},startTime:0,speed:{},easingLoop:null};function r(n,r){var i=Math.floor(t.scrollTop),a=t.scrollLeft,o=Math.abs(n),s=Math.abs(r);if(s>o){if(r<0&&i===e.contentHeight-e.containerHeight||r>0&&i===0)return window.scrollY===0&&r>0&&X.isChrome}else if(o>s&&(n<0&&a===e.contentWidth-e.containerWidth||n>0&&a===0))return!0;return!0}function i(n,r){t.scrollTop-=r,t.scrollLeft-=n,Z(e)}function a(e){return e.targetTouches?e.targetTouches[0]:e}function o(t){return t.target===e.scrollbarX||t.target===e.scrollbarY||t.pointerType&&t.pointerType===`pen`&&t.buttons===0?!1:!!(t.targetTouches&&t.targetTouches.length===1||t.pointerType&&t.pointerType!==`mouse`&&t.pointerType!==t.MSPOINTER_TYPE_MOUSE)}function s(e){if(o(e)){var t=a(e);n.startOffset.pageX=t.pageX,n.startOffset.pageY=t.pageY,n.startTime=new Date().getTime(),n.easingLoop!==null&&clearInterval(n.easingLoop)}}function c(e,n,r){if(!t.contains(e))return!1;for(var i=e;i&&i!==t;){if(i.classList.contains(W.element.consuming))return!0;var a=z(i);if(r&&a.overflowY.match(/(scroll|auto)/)){var o=i.scrollHeight-i.clientHeight;if(o>0&&(i.scrollTop>0&&r<0||i.scrollTop<o&&r>0))return!0}if(n&&a.overflowX.match(/(scroll|auto)/)){var s=i.scrollWidth-i.clientWidth;if(s>0&&(i.scrollLeft>0&&n<0||i.scrollLeft<s&&n>0))return!0}i=i.parentNode}return!1}function l(e){if(o(e)){var t=a(e),s={pageX:t.pageX,pageY:t.pageY},l=s.pageX-n.startOffset.pageX,u=s.pageY-n.startOffset.pageY;if(c(e.target,l,u))return;i(l,u),n.startOffset=s;var d=new Date().getTime(),f=d-n.startTime;f>0&&(n.speed.x=l/f,n.speed.y=u/f,n.startTime=d),r(l,u)&&e.cancelable&&e.preventDefault()}}function u(){e.settings.swipeEasing&&(clearInterval(n.easingLoop),n.easingLoop=setInterval(function(){if(e.isInitialized){clearInterval(n.easingLoop);return}if(!n.speed.x&&!n.speed.y){clearInterval(n.easingLoop);return}if(Math.abs(n.speed.x)<.01&&Math.abs(n.speed.y)<.01){clearInterval(n.easingLoop);return}i(n.speed.x*30,n.speed.y*30),n.speed.x*=.8,n.speed.y*=.8},10))}X.supportsTouch?(e.event.bind(t,`touchstart`,s),e.event.bind(t,`touchmove`,l),e.event.bind(t,`touchend`,u)):X.supportsIePointer&&(window.PointerEvent?(e.event.bind(t,`pointerdown`,s),e.event.bind(t,`pointermove`,l),e.event.bind(t,`pointerup`,u)):window.MSPointerEvent&&(e.event.bind(t,`MSPointerDown`,s),e.event.bind(t,`MSPointerMove`,l),e.event.bind(t,`MSPointerUp`,u)))}var yt=function(){return{handlers:[`click-rail`,`drag-thumb`,`keyboard`,`wheel`,`touch`],maxScrollbarLength:null,minScrollbarLength:null,scrollingThreshold:1e3,scrollXMarginOffset:0,scrollYMarginOffset:0,suppressScrollX:!1,suppressScrollY:!1,swipeEasing:!0,useBothWheelAxes:!1,wheelPropagation:!0,wheelSpeed:1}},bt={"click-rail":pt,"drag-thumb":mt,keyboard:gt,wheel:_t,touch:vt},$=function(e,t){var n=this;if(t===void 0&&(t={}),typeof e==`string`&&(e=document.querySelector(e)),!e||!e.nodeName)throw Error(`no element is specified to initialize PerfectScrollbar`);for(var r in this.element=e,e.classList.add(W.main),this.settings=yt(),t)this.settings[r]=t[r];this.containerWidth=null,this.containerHeight=null,this.contentWidth=null,this.contentHeight=null;var i=function(){return e.classList.add(W.state.focus)},a=function(){return e.classList.remove(W.state.focus)};this.isRtl=z(e).direction===`rtl`,this.isRtl===!0&&e.classList.add(W.rtl),this.isNegativeScroll=(function(){var t=e.scrollLeft,n=null;return e.scrollLeft=-1,n=e.scrollLeft<0,e.scrollLeft=t,n})(),this.negativeScrollAdjustment=this.isNegativeScroll?e.scrollWidth-e.clientWidth:0,this.event=new K,this.ownerDocument=e.ownerDocument||document,this.scrollbarXRail=V(W.element.rail(`x`)),e.appendChild(this.scrollbarXRail),this.scrollbarX=V(W.element.thumb(`x`)),this.scrollbarXRail.appendChild(this.scrollbarX),this.scrollbarX.setAttribute(`tabindex`,0),this.event.bind(this.scrollbarX,`focus`,i),this.event.bind(this.scrollbarX,`blur`,a),this.scrollbarXActive=null,this.scrollbarXWidth=null,this.scrollbarXLeft=null;var o=z(this.scrollbarXRail);this.scrollbarXBottom=parseInt(o.bottom,10),isNaN(this.scrollbarXBottom)?(this.isScrollbarXUsingBottom=!1,this.scrollbarXTop=Y(o.top)):this.isScrollbarXUsingBottom=!0,this.railBorderXWidth=Y(o.borderLeftWidth)+Y(o.borderRightWidth),B(this.scrollbarXRail,{display:`block`}),this.railXMarginWidth=Y(o.marginLeft)+Y(o.marginRight),B(this.scrollbarXRail,{display:``}),this.railXWidth=null,this.railXRatio=null,this.scrollbarYRail=V(W.element.rail(`y`)),e.appendChild(this.scrollbarYRail),this.scrollbarY=V(W.element.thumb(`y`)),this.scrollbarYRail.appendChild(this.scrollbarY),this.scrollbarY.setAttribute(`tabindex`,0),this.event.bind(this.scrollbarY,`focus`,i),this.event.bind(this.scrollbarY,`blur`,a),this.scrollbarYActive=null,this.scrollbarYHeight=null,this.scrollbarYTop=null;var s=z(this.scrollbarYRail);this.scrollbarYRight=parseInt(s.right,10),isNaN(this.scrollbarYRight)?(this.isScrollbarYUsingRight=!1,this.scrollbarYLeft=Y(s.left)):this.isScrollbarYUsingRight=!0,this.scrollbarYOuterWidth=this.isRtl?ut(this.scrollbarY):null,this.railBorderYWidth=Y(s.borderTopWidth)+Y(s.borderBottomWidth),B(this.scrollbarYRail,{display:`block`}),this.railYMarginHeight=Y(s.marginTop)+Y(s.marginBottom),B(this.scrollbarYRail,{display:``}),this.railYHeight=null,this.railYRatio=null,this.reach={x:e.scrollLeft<=0?`start`:e.scrollLeft>=this.contentWidth-this.containerWidth?`end`:null,y:e.scrollTop<=0?`start`:e.scrollTop>=this.contentHeight-this.containerHeight?`end`:null},this.isAlive=!0,this.settings.handlers.forEach(function(e){return bt[e](n)}),this.lastScrollTop=Math.floor(e.scrollTop),this.lastScrollLeft=e.scrollLeft,this.event.bind(this.element,`scroll`,function(e){return n.onScroll(e)}),Z(this)};$.prototype.update=function(){this.isAlive&&(this.negativeScrollAdjustment=this.isNegativeScroll?this.element.scrollWidth-this.element.clientWidth:0,B(this.scrollbarXRail,{display:`block`}),B(this.scrollbarYRail,{display:`block`}),this.railXMarginWidth=Y(z(this.scrollbarXRail).marginLeft)+Y(z(this.scrollbarXRail).marginRight),this.railYMarginHeight=Y(z(this.scrollbarYRail).marginTop)+Y(z(this.scrollbarYRail).marginBottom),B(this.scrollbarXRail,{display:`none`}),B(this.scrollbarYRail,{display:`none`}),Z(this),J(this,`top`,0,!1,!0),J(this,`left`,0,!1,!0),B(this.scrollbarXRail,{display:``}),B(this.scrollbarYRail,{display:``}))},$.prototype.onScroll=function(e){this.isAlive&&(Z(this),J(this,`top`,this.element.scrollTop-this.lastScrollTop),J(this,`left`,this.element.scrollLeft-this.lastScrollLeft),this.lastScrollTop=Math.floor(this.element.scrollTop),this.lastScrollLeft=this.element.scrollLeft)},$.prototype.destroy=function(){this.isAlive&&=(this.event.unbindAll(),U(this.scrollbarX),U(this.scrollbarY),U(this.scrollbarXRail),U(this.scrollbarYRail),this.removePsClasses(),this.element=null,this.scrollbarX=null,this.scrollbarY=null,this.scrollbarXRail=null,this.scrollbarYRail=null,!1)},$.prototype.removePsClasses=function(){this.element.className=this.element.className.split(` `).filter(function(e){return!e.match(/^ps([-_].+|)$/)}).join(` `)};var xt=m({__name:`PerfectScrollbar`,props:{tag:{default:`div`},options:{default:()=>({})}},emits:[`scroll`,`ps-scroll-y`,`ps-scroll-x`,`ps-scroll-up`,`ps-scroll-down`,`ps-scroll-left`,`ps-scroll-right`,`ps-y-reach-start`,`ps-y-reach-end`,`ps-x-reach-start`,`ps-x-reach-end`],setup(e,{expose:t,emit:n}){let a=e,o=n,s=p(null),u=p(null);d(()=>a.options,()=>{h(),m()},{deep:!0}),i(()=>{s.value&&m()}),x(()=>{h()});function m(){s.value&&(u.value=new $(s.value,a.options),v())}function h(){u.value&&=(v(!1),u.value.destroy(),null)}let g={scroll:_(`scroll`),"ps-scroll-y":_(`ps-scroll-y`),"ps-scroll-x":_(`ps-scroll-x`),"ps-scroll-up":_(`ps-scroll-up`),"ps-scroll-down":_(`ps-scroll-down`),"ps-scroll-left":_(`ps-scroll-left`),"ps-scroll-right":_(`ps-scroll-right`),"ps-y-reach-start":_(`ps-y-reach-start`),"ps-y-reach-end":_(`ps-y-reach-end`),"ps-x-reach-start":_(`ps-x-reach-start`),"ps-x-reach-end":_(`ps-x-reach-end`)};function _(e){return function(t){o(e,t)}}function v(e=!0){var t;(t=u.value)!=null&&t.element&&Object.entries(g).forEach(([t,n])=>{var r,i;e?(r=u.value)==null||r.element.addEventListener(t,n):(i=u.value)==null||i.element.removeEventListener(t,n)})}return t({ps:u}),(e,t)=>(r(),T(l(e.tag),{ref_key:`scrollbar`,ref:s,class:`ps`},{default:f(()=>[c(e.$slots,`default`)]),_:3},512))}}),St=e(ae(),1),Ct=e(he(),1),wt={class:`ninjadash-nav-actions__item ninjadash-nav-actions__notification`},Tt={key:0,class:`ninjadash-top-dropdown__nav notification-list`},Et={class:`ninjadash-top-dropdown__content notifications`},Dt={class:`notification-content d-flex`},Ot={class:`notification-text`},kt={class:`notification-status`},At={key:1,class:`notification-empty`},jt=L(m({__name:`Notification`,setup(e){St.default.extend(Ct.default);let a=p([]),s=p(0),c=p(!0),l={CRITICAL:{icon:`exclamation-triangle`,class:`bg-danger`},WARNING:{icon:`bell`,class:`bg-warning`},INFO:{icon:`info-circle`,class:`bg-primary`}},u=h(()=>a.value.length>0),d=p(null),m=()=>{d.value&&(d.value.visible=!1)};async function b(){try{let[e,t]=await Promise.all([de.get(`/component/events/severity-stat`),de.get(`/component/events`,{not_resolved:!0,limit:6})]),n=e.data.data;s.value=(n.WARNING||0)+(n.CRITICAL||0),a.value=(t.data.data||[]).slice(0,6)}catch(e){console.error(`Failed to load event notifications`,e)}finally{c.value=!1}}i(b);let w=fe.subscribe(`event:webhook:alertmanager`,()=>b()),E=fe.subscribe(`event:storage:c_events:added`,()=>b()),D=fe.subscribe(`event:storage:c_events:updated`,()=>b());return x(()=>{w(),E(),D()}),(e,i)=>{let p=ue,h=n(`sdHeading`),b=n(`unicon`),x=n(`router-link`),w=n(`sdPopover`);return r(),v(`div`,wt,[A(w,{ref_key:`notifPopoverRef`,ref:d,placement:`bottomLeft`,action:`click`},{content:f(()=>[A(g(et),{class:`ninjadash-top-dropdown`},{default:f(()=>[A(h,{as:`h5`,class:`ninjadash-top-dropdown__title`},{default:f(()=>[i[0]||=_(`span`,{class:`title-text`},`Unresolved events`,-1),s.value?(r(),T(p,{key:0,class:`badge-danger`,count:s.value,"overflow-count":99},null,8,[`count`])):C(``,!0)]),_:1}),A(g(xt),{options:{wheelSpeed:1,swipeEasing:!0,suppressScrollX:!0}},{default:f(()=>[u.value?(r(),v(`ul`,Tt,[(r(!0),v(y,null,t(a.value,e=>(r(),v(`li`,{key:e.id},[A(x,{to:{name:`events`},onClick:m},{default:f(()=>[_(`div`,Et,[_(`div`,{class:S([`notification-icon`,l[e.severity]?.class||`bg-primary`])},[A(b,{name:l[e.severity]?.icon||`bell`},null,8,[`name`])],2),_(`div`,Dt,[_(`div`,Ot,[A(h,{as:`h5`},{default:f(()=>[O(o(e.annotation),1)]),_:2},1024),_(`p`,null,o(e.device?.name?e.device.name+` · `:``)+o(g(St.default)(e.created_at).fromNow()),1)]),_(`div`,kt,[A(p,{dot:``})])])])]),_:2},1024)]))),128))])):c.value?C(``,!0):(r(),v(`div`,At,`No unresolved events right now.`))]),_:1}),A(x,{class:`btn-seeAll`,to:{name:`events`},onClick:m},{default:f(()=>[...i[1]||=[O(` See all events `,-1)]]),_:1})]),_:1})]),default:f(()=>[A(p,{dot:s.value>0,offset:[-8,-5]},{default:f(()=>[...i[2]||=[_(`a`,{to:`#`,class:`ninjadash-nav-action-link`},[_(`img`,{src:`data:image/svg+xml,%3csvg%20xmlns='http://www.w3.org/2000/svg'%20width='16.041'%20height='20.001'%20viewBox='0%200%2016.041%2020.001'%3e%3cpath%20id='bell'%20d='M18.035,13.208V10.02A6.015,6.015,0,0,0,13.023,4.1V3a1,1,0,1,0-2.005,0V4.1A6.015,6.015,0,0,0,6.005,10.02v3.188A3.008,3.008,0,0,0,4,16.035V18.04a1,1,0,0,0,1,1H8.15a4.01,4.01,0,0,0,7.74,0h3.148a1,1,0,0,0,1-1V16.035a3.008,3.008,0,0,0-2.005-2.827ZM8.01,10.02a4.01,4.01,0,0,1,8.02,0v3.008H8.01Zm4.01,10.025a2.005,2.005,0,0,1-1.724-1h3.449A2.005,2.005,0,0,1,12.02,20.046Zm6.015-3.008H6.005v-1a1,1,0,0,1,1-1H17.033a1,1,0,0,1,1,1Z'%20transform='translate(-4%20-2)'%20fill='%23a0a0a0'/%3e%3c/svg%3e`})],-1)]]),_:1},8,[`dot`])]),_:1},512)])}}}),[[`__scopeId`,`data-v-5985f727`]]),Mt={class:`ninjadash-nav-actions__item ninjadash-nav-actions__author`},Nt={class:`account-menu`},Pt={class:`account-menu__header`},Ft={class:`account-menu__avatar`},It={class:`account-menu__identity`},Lt={class:`account-menu__name`},Rt={key:0,class:`account-menu__login`},zt={key:1,class:`account-menu__role`},Bt={class:`account-menu__list`},Vt={class:`account-menu__item-icon`},Ht={class:`account-menu__item-icon`},Ut={to:`#`,class:`ninjadash-nav-action-link`},Wt={class:`ninjadash-nav-actions__author-avatar`},Gt={class:`ninjadash-nav-actions__author--name`},Kt=L(m({__name:`Info`,setup(e){let{dispatch:t,state:i}=le(),{push:a}=F(),s=h(()=>i.auth.user),c=h(()=>s.value?.name||`Unknown user`),l=h(()=>s.value?.login||``),u=h(()=>s.value?.role?.name||``),d=h(()=>(c.value?.[0]||`?`).toUpperCase()),m=p(null),y=()=>{m.value&&(m.value.visible=!1)},b=async e=>{e.preventDefault(),y(),await t(`logOut`),a(`/auth/login`)};return(e,t)=>{let i=n(`unicon`),a=n(`router-link`),s=n(`sdPopover`);return r(),T(g($e),null,{default:f(()=>[A(jt),_(`div`,Mt,[A(s,{ref_key:`accountMenuRef`,ref:m,placement:`bottomRight`,action:`click`,"overlay-class-name":`account-menu-popover`},{content:f(()=>[_(`div`,Nt,[_(`div`,Pt,[_(`span`,Ft,o(d.value),1),_(`div`,It,[_(`p`,Lt,o(c.value),1),l.value?(r(),v(`p`,Rt,`@`+o(l.value),1)):C(``,!0),u.value?(r(),v(`span`,zt,[A(i,{name:`shield`}),O(` `+o(u.value),1)])):C(``,!0)])]),t[2]||=_(`div`,{class:`account-menu__divider`},null,-1),_(`ul`,Bt,[_(`li`,null,[A(a,{to:{name:`account-settings`},class:`account-menu__item`,onClick:y},{default:f(()=>[_(`span`,Vt,[A(i,{name:`setting`})]),t[0]||=_(`span`,null,`Account settings`,-1)]),_:1})])]),t[3]||=_(`div`,{class:`account-menu__divider`},null,-1),_(`button`,{type:`button`,class:`account-menu__item account-menu__item--danger`,onClick:b},[_(`span`,Ht,[A(i,{name:`signout`})]),t[1]||=_(`span`,null,`Sign out`,-1)])])]),default:f(()=>[_(`a`,Ut,[_(`span`,Wt,o(d.value),1),_(`span`,Gt,o(c.value),1),A(i,{name:`angle-down`})])]),_:1},512)])]),_:1})}}}),[[`__scopeId`,`data-v-7cf2d004`]]),qt=m({__name:`Aside`,props:{toggleCollapsed:{type:Function,required:!0},events:{type:Object,required:!0}},setup(e){let i=e,a=le(),s=h(()=>a.state.themeLayout.data),c=p(`inline`),{events:l}=D(i),{onRtlChange:d,onLtrChange:m,modeChangeDark:_,modeChangeLight:x,modeChangeTopNav:S,modeChangeSideNav:C}=l.value,w=se(),E=b({selectedKeys:[],openKeys:[]}),k={"device-management":`device-mgmt`,"device-management-create":`device-mgmt`,"device-management-edit":`device-mgmt`,"device-access":`device-mgmt`,"device-group":`device-mgmt`,"device-model":`device-mgmt`,"device-model-edit":`device-mgmt`,autodiscovery:`device-mgmt`,"ont-list":`interfaces`,"favorite-interfaces":`interfaces`,"tagged-interfaces":`interfaces`,"links-list":`links`,"topology-tree":`links`,"analytics-increasing-errors":`analytics`,"analytics-ont-statuses":`analytics`,"analytics-duplicated-mac":`analytics`,"analytics-ont-level-strength":`analytics`,"analytics-duplicated-onts":`analytics`,"analytics-device-statuses":`analytics`,"logs-console":`logs`,"logs-actions":`logs`,"logs-device-calling":`logs`,"logs-traps":`logs`,"logs-poller":`logs`,"logs-schedule-reports":`logs`,users:`user-mgmt`,"users-create":`user-mgmt`,"users-edit":`user-mgmt`,"user-roles":`user-mgmt`,"user-roles-create":`user-mgmt`,"user-roles-edit":`user-mgmt`,macros:`config`,"onts-registration":`config`,"notifications-config":`config`,"events-config":`config`,"system-config":`config`,"qr-devices":`qr`,"qr-interfaces":`qr`};u(()=>{let e=w.name;if(!e)return;E.selectedKeys=[e];let t=k[e];E.openKeys=t?[t]:[]});let j=e=>{E.openKeys=e.length?[e[e.length-1]]:[]},M=[{key:`oxidized`,label:`Oxidized`,href:`/oxidized/`,icon:`save`},{key:`grafana`,label:`Grafana`,href:`/grafana/`,icon:`chart-line`},{key:`prometheus`,label:`Prometheus`,href:`/prometheus/`,icon:`fire`},{key:`alertmanager`,label:`Alertmanager`,href:`/alertmanager/`,icon:`bell`},{key:`phpmyadmin`,label:`phpMyAdmin`,href:`/phpmyadmin/`,icon:`database`}],N=Object.fromEntries(M.map(e=>[e.key,e.href])),ee=F(),te=({key:e})=>{if(i.toggleCollapsed(),e in N){window.open(N[e],`_blank`,`noopener`);return}ee.push({name:e})};return(e,i)=>{let a=n(`unicon`),l=re,u=ne,d=ie;return r(),T(d,{"open-keys":E.openKeys,selectedKeys:E.selectedKeys,"onUpdate:selectedKeys":i[0]||=e=>E.selectedKeys=e,mode:c.value,theme:s.value?`dark`:`light`,class:`scroll-menu`,onOpenChange:j,onClick:te},{default:f(()=>[A(l,{key:`dashboard`},{icon:f(()=>[A(a,{name:`create-dashboard`})]),default:f(()=>[i[1]||=O(` Dashboard `,-1)]),_:1}),A(l,{key:`devices-list`},{icon:f(()=>[A(a,{name:`server-network`})]),default:f(()=>[i[2]||=O(` Devices `,-1)]),_:1}),A(l,{key:`topology-graph`},{icon:f(()=>[A(a,{name:`graph-bar`})]),default:f(()=>[i[3]||=O(` Topology graph `,-1)]),_:1}),A(u,{key:`interfaces`},{icon:f(()=>[A(a,{name:`wifi`})]),title:f(()=>[...i[4]||=[O(`Interfaces`,-1)]]),default:f(()=>[A(l,{key:`ont-list`},{icon:f(()=>[A(a,{name:`signal-alt-3`})]),default:f(()=>[i[5]||=O(` ONT list `,-1)]),_:1}),A(l,{key:`favorite-interfaces`},{icon:f(()=>[A(a,{name:`star`})]),default:f(()=>[i[6]||=O(` Favorite list `,-1)]),_:1}),A(l,{key:`tagged-interfaces`},{icon:f(()=>[A(a,{name:`tag-alt`})]),default:f(()=>[i[7]||=O(` Tags `,-1)]),_:1})]),_:1}),A(u,{key:`links`},{icon:f(()=>[A(a,{name:`share-alt`})]),title:f(()=>[...i[8]||=[O(`Links`,-1)]]),default:f(()=>[A(l,{key:`links-list`},{icon:f(()=>[A(a,{name:`link-alt`})]),default:f(()=>[i[9]||=O(` Links list `,-1)]),_:1}),A(l,{key:`topology-tree`},{icon:f(()=>[A(a,{name:`sitemap`})]),default:f(()=>[i[10]||=O(` Topology (tree view) `,-1)]),_:1})]),_:1}),A(l,{key:`map`},{icon:f(()=>[A(a,{name:`map`})]),default:f(()=>[i[11]||=O(` Map `,-1)]),_:1}),A(l,{key:`nearby`},{icon:f(()=>[A(a,{name:`location-point`})]),default:f(()=>[i[12]||=O(` Nearby objects `,-1)]),_:1}),A(l,{key:`events`},{icon:f(()=>[A(a,{name:`bell`})]),default:f(()=>[i[13]||=O(` Events `,-1)]),_:1}),A(u,{key:`analytics`},{icon:f(()=>[A(a,{name:`chart-line`})]),title:f(()=>[...i[14]||=[O(`Analytics`,-1)]]),default:f(()=>[A(l,{key:`analytics-increasing-errors`},{icon:f(()=>[A(a,{name:`arrow-growth`})]),default:f(()=>[i[15]||=O(` Increasing errors `,-1)]),_:1}),A(l,{key:`analytics-ont-statuses`},{icon:f(()=>[A(a,{name:`signal-alt-3`})]),default:f(()=>[i[16]||=O(` ONT statuses `,-1)]),_:1}),A(l,{key:`analytics-duplicated-mac`},{icon:f(()=>[A(a,{name:`copy`})]),default:f(()=>[i[17]||=O(` Duplicated MACs `,-1)]),_:1}),A(l,{key:`analytics-ont-level-strength`},{icon:f(()=>[A(a,{name:`signal-alt`})]),default:f(()=>[i[18]||=O(` Strength level ONTs `,-1)]),_:1}),A(l,{key:`analytics-duplicated-onts`},{icon:f(()=>[A(a,{name:`copy-alt`})]),default:f(()=>[i[19]||=O(` Duplicated ONTs `,-1)]),_:1}),A(l,{key:`analytics-device-statuses`},{icon:f(()=>[A(a,{name:`server`})]),default:f(()=>[i[20]||=O(` Device statuses `,-1)]),_:1})]),_:1}),A(u,{key:`logs`},{icon:f(()=>[A(a,{name:`document-layout-left`})]),title:f(()=>[...i[21]||=[O(`Logs`,-1)]]),default:f(()=>[A(l,{key:`logs-console`},{icon:f(()=>[A(a,{name:`window-section`})]),default:f(()=>[i[22]||=O(` Console logs `,-1)]),_:1}),A(l,{key:`logs-actions`},{icon:f(()=>[A(a,{name:`history`})]),default:f(()=>[i[23]||=O(` Actions `,-1)]),_:1}),A(l,{key:`logs-device-calling`},{icon:f(()=>[A(a,{name:`exchange`})]),default:f(()=>[i[24]||=O(` Device calling logs `,-1)]),_:1}),A(l,{key:`logs-traps`},{icon:f(()=>[A(a,{name:`bell`})]),default:f(()=>[i[25]||=O(` SNMP traps `,-1)]),_:1}),A(l,{key:`logs-poller`},{icon:f(()=>[A(a,{name:`sync`})]),default:f(()=>[i[26]||=O(` Poller logs `,-1)]),_:1}),A(l,{key:`logs-schedule-reports`},{icon:f(()=>[A(a,{name:`calendar-alt`})]),default:f(()=>[i[27]||=O(` Schedule reports `,-1)]),_:1})]),_:1}),A(g(ve),{class:`ninjadash-sidebar-nav-title`},{default:f(()=>[...i[28]||=[O(`Management`,-1)]]),_:1}),A(u,{key:`device-mgmt`},{icon:f(()=>[A(a,{name:`server`})]),title:f(()=>[...i[29]||=[O(`Device management`,-1)]]),default:f(()=>[A(l,{key:`device-management`},{icon:f(()=>[A(a,{name:`edit`})]),default:f(()=>[i[30]||=O(` Device management `,-1)]),_:1}),A(l,{key:`device-access`},{icon:f(()=>[A(a,{name:`lock`})]),default:f(()=>[i[31]||=O(` Accesses `,-1)]),_:1}),A(l,{key:`device-group`},{icon:f(()=>[A(a,{name:`layer-group`})]),default:f(()=>[i[32]||=O(` Groups `,-1)]),_:1}),A(l,{key:`device-model`},{icon:f(()=>[A(a,{name:`box`})]),default:f(()=>[i[33]||=O(` Models `,-1)]),_:1}),A(l,{key:`autodiscovery`},{icon:f(()=>[A(a,{name:`search`})]),default:f(()=>[i[34]||=O(` Autodiscovery `,-1)]),_:1})]),_:1}),A(u,{key:`user-mgmt`},{icon:f(()=>[A(a,{name:`users-alt`})]),title:f(()=>[...i[35]||=[O(`Users`,-1)]]),default:f(()=>[A(l,{key:`users`},{icon:f(()=>[A(a,{name:`user`})]),default:f(()=>[i[36]||=O(` Users `,-1)]),_:1}),A(l,{key:`user-roles`},{icon:f(()=>[A(a,{name:`shield-check`})]),default:f(()=>[i[37]||=O(` Roles `,-1)]),_:1})]),_:1}),A(u,{key:`config`},{icon:f(()=>[A(a,{name:`setting`})]),title:f(()=>[...i[38]||=[O(`Configuration`,-1)]]),default:f(()=>[A(l,{key:`macros`},{icon:f(()=>[A(a,{name:`brackets-curly`})]),default:f(()=>[i[39]||=O(` Macros `,-1)]),_:1}),A(l,{key:`onts-registration`},{icon:f(()=>[A(a,{name:`clipboard-notes`})]),default:f(()=>[i[40]||=O(` ONTs registration `,-1)]),_:1}),A(l,{key:`notifications-config`},{icon:f(()=>[A(a,{name:`bell`})]),default:f(()=>[i[41]||=O(` Notifications `,-1)]),_:1}),A(l,{key:`events-config`},{icon:f(()=>[A(a,{name:`exclamation-triangle`})]),default:f(()=>[i[42]||=O(` Event configuration `,-1)]),_:1}),A(l,{key:`system-config`},{icon:f(()=>[A(a,{name:`setting`})]),default:f(()=>[i[43]||=O(` System configuration `,-1)]),_:1})]),_:1}),A(g(ve),{class:`ninjadash-sidebar-nav-title`},{default:f(()=>[...i[44]||=[O(`External apps`,-1)]]),_:1}),(r(),v(y,null,t(M,e=>A(l,{key:e.key},{icon:f(()=>[A(a,{name:e.icon},null,8,[`name`])]),default:f(()=>[O(` `+o(e.label),1)]),_:2},1024)),64)),A(g(ve),{class:`ninjadash-sidebar-nav-title`},{default:f(()=>[...i[45]||=[O(`QR printing`,-1)]]),_:1}),A(u,{key:`qr`},{icon:f(()=>[A(a,{name:`qrcode-scan`})]),title:f(()=>[...i[46]||=[O(`QR printing`,-1)]]),default:f(()=>[A(l,{key:`qr-devices`},{icon:f(()=>[A(a,{name:`server`})]),default:f(()=>[i[47]||=O(` Devices `,-1)]),_:1}),A(l,{key:`qr-interfaces`},{icon:f(()=>[A(a,{name:`wifi`})]),default:f(()=>[i[48]||=O(` Interfaces `,-1)]),_:1})]),_:1})]),_:1},8,[`open-keys`,`selectedKeys`,`mode`,`theme`])}}}),Jt={class:`ninjadash-top-menu`},Yt={class:`has-subMenu`},Xt={class:`subMenu`},Zt={class:`has-subMenu`},Qt={class:`subMenu`},$t={class:`has-subMenu-left`},en={href:`#`,class:`parent`},tn={class:`subMenu`},nn={class:`has-subMenu`},rn={class:`subMenu`},an={class:`has-subMenu-left`},on={href:`#`,class:`parent`},sn={class:`subMenu`},cn={class:`has-subMenu-left`},ln={href:`#`,class:`parent`},un={class:`subMenu`},dn={class:`has-subMenu-left`},fn={href:`#`,class:`parent`},pn={class:`subMenu`},mn={class:`has-subMenu-left`},hn={href:`#`,class:`parent`},gn={class:`subMenu`},_n={class:`mega-item has-subMenu`},vn={class:`megaMenu-wrapper megaMenu-small`},yn={class:`mega-item has-subMenu`},bn={class:`megaMenu-wrapper megaMenu-wide`},xn={class:`has-subMenu`},Sn={class:`subMenu`},Cn={class:`has-subMenu-left`},wn={href:`#`,class:`parent`},Tn={class:`subMenu`},En={class:`has-subMenu-left`},Dn={class:`subMenu`},On={class:`has-subMenu-left`},kn={href:`#`,class:`parent`},An={class:`subMenu`},jn={class:`has-subMenu-left`},Mn={href:`#`,class:`parent`},Nn={class:`subMenu`},Pn={class:`has-subMenu-left`},Fn={href:`#`,class:`parent`},In={class:`subMenu`},Ln={class:`has-subMenu-left`},Rn={href:`#`,class:`parent`},zn={class:`subMenu`},Bn={class:`has-subMenu-left`},Vn={href:`#`,class:`parent`},Hn={class:`subMenu`},Un={class:`has-subMenu-left`},Wn={href:`#`,class:`parent`},Gn={class:`subMenu`},Kn=m({__name:`TopMenuItems`,setup(e){i(()=>{let e=document.querySelector(`.ninjadash-top-menu a.active`);window.addEventListener(`load`,e&&(()=>{let t=e.closest(`.megaMenu-wrapper`),n=e.closest(`.has-subMenu-left`);t?e.closest(`.megaMenu-wrapper`).previousSibling.classList.add(`active`):(e.closest(`ul`).previousSibling.classList.add(`active`),n&&n.closest(`ul`).previousSibling.classList.add(`active`))})),ge()});let t=e=>{document.querySelectorAll(`.parent`).forEach(e=>{e.classList.remove(`active`)});let t=e.currentTarget.closest(`.has-subMenu-left`);e.currentTarget.closest(`.megaMenu-wrapper`)?e.currentTarget.closest(`.megaMenu-wrapper`).previousSibling.classList.add(`active`):(e.currentTarget.closest(`ul`).previousSibling.classList.add(`active`),t&&t.closest(`ul`).previousSibling.classList.add(`active`))};return(e,i)=>{let a=n(`router-link`),o=n(`unicon`);return r(),T(g(xe),null,{default:f(()=>[_(`div`,Jt,[_(`ul`,null,[_(`li`,Yt,[i[5]||=_(`a`,{href:`#`,class:`parent`},` Dashboard `,-1),_(`ul`,Xt,[_(`li`,{onClick:t},[A(a,{to:`/demo-one`},{default:f(()=>[...i[0]||=[O(`Demo 1`,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/demo-two`},{default:f(()=>[...i[1]||=[O(`Demo 2`,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/demo-three`},{default:f(()=>[...i[2]||=[O(`Demo 3`,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/demo-four`},{default:f(()=>[...i[3]||=[O(`Demo 4`,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/demo-five`},{default:f(()=>[...i[4]||=[O(`Demo 5`,-1)]]),_:1})])])]),_(`li`,Zt,[i[9]||=_(`a`,{href:`#`,class:`parent`},` Crud `,-1),_(`ul`,Qt,[_(`li`,$t,[_(`a`,en,[A(o,{name:`database`}),i[6]||=O(` Axios Crud `,-1)]),_(`ul`,tn,[_(`li`,{onClick:t},[A(a,{to:`/crud/axios-view`},{default:f(()=>[...i[7]||=[O(` View All `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/crud/axios-add`},{default:f(()=>[...i[8]||=[O(` Add New `,-1)]]),_:1})])])])])]),_(`li`,nn,[i[32]||=_(`a`,{href:`#`,class:`parent`},` Apps `,-1),_(`ul`,rn,[_(`li`,an,[_(`a`,on,[A(o,{name:`envelope`}),i[10]||=O(` Email `,-1)]),_(`ul`,sn,[_(`li`,{onClick:t},[A(a,{to:`/app/mail/inbox`},{default:f(()=>[...i[11]||=[O(` Inbox `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/app/mail-single/1585118055048`},{default:f(()=>[...i[12]||=[O(` Read Email `,-1)]]),_:1})])])]),_(`li`,{onClick:t},[A(a,{to:`/app/chat/private/rofiq@gmail.com`},{default:f(()=>[A(o,{name:`comment-alt`}),i[13]||=O(` Chat `,-1)]),_:1})]),_(`li`,cn,[_(`a`,ln,[A(o,{name:`shopping-cart`}),i[14]||=O(` eComerce `,-1)]),_(`ul`,un,[_(`li`,{onClick:t},[A(a,{to:`/app/ecommerce/product/grid`},{default:f(()=>[...i[15]||=[O(` Products `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/app/ecommerce/productDetails/1`},{default:f(()=>[...i[16]||=[O(` Products Details `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/app/ecommerce/add-product`},{default:f(()=>[...i[17]||=[O(` Product Add `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/app/ecommerce/edit-product`},{default:f(()=>[...i[18]||=[O(` Product Edit `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/app/ecommerce/cart`},{default:f(()=>[...i[19]||=[O(` Cart `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/app/ecommerce/orders`},{default:f(()=>[...i[20]||=[O(` Orders `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/app/ecommerce/sellers`},{default:f(()=>[...i[21]||=[O(` Sellers `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/app/ecommerce/Invoice`},{default:f(()=>[...i[22]||=[O(` Invoices `,-1)]]),_:1})])])]),_(`li`,dn,[_(`a`,fn,[A(o,{name:`shutter-alt`}),i[23]||=O(` Social App `,-1)]),_(`ul`,pn,[_(`li`,{onClick:t},[A(a,{to:`/app/social/profile/overview`},{default:f(()=>[...i[24]||=[O(` My Profile `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/app/social/profile/timeline`},{default:f(()=>[...i[25]||=[O(` Timeline `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/app/social/profile/activity`},{default:f(()=>[...i[26]||=[O(` Activity `,-1)]]),_:1})])])]),_(`li`,mn,[_(`a`,hn,[A(o,{name:`bullseye`}),i[27]||=O(` Project `,-1)]),_(`ul`,gn,[_(`li`,{onClick:t},[A(a,{to:`/app/project/grid`},{default:f(()=>[...i[28]||=[O(` Project Grid `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/app/project/list`},{default:f(()=>[...i[29]||=[O(` Project List `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/app/project/create`},{default:f(()=>[...i[30]||=[O(` Create Project `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/app/project/projectDetails/1`},{default:f(()=>[...i[31]||=[O(` Project Details `,-1)]]),_:1})])])])])]),_(`li`,_n,[i[46]||=_(`a`,{href:`#`,class:`parent`},` Pages `,-1),_(`ul`,vn,[_(`li`,null,[_(`ul`,null,[_(`li`,{onClick:t},[A(a,{to:`/page/profile-settings`},{default:f(()=>[...i[33]||=[O(` Settings `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/page/gallery`},{default:f(()=>[...i[34]||=[O(` Gallery `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/page/pricing`},{default:f(()=>[...i[35]||=[O(` Pricing `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/page/banners`},{default:f(()=>[...i[36]||=[O(` Banners `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/page/testimonials`},{default:f(()=>[...i[37]||=[O(` Testimonials `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/page/faqs`},{default:f(()=>[...i[38]||=[O(" Faq`s ",-1)]]),_:1})])])]),_(`li`,null,[_(`ul`,null,[_(`li`,{onClick:t},[A(a,{to:`/page/search`},{default:f(()=>[...i[39]||=[O(` Search Results `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/page/starter`},{default:f(()=>[...i[40]||=[O(` Blank Page `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/page/maintenance`},{default:f(()=>[...i[41]||=[O(` Maintenance `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/page/404`},{default:f(()=>[...i[42]||=[O(` 404 `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/page/comingSoon`},{default:f(()=>[...i[43]||=[O(` Coming Soon `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/page/support`},{default:f(()=>[...i[44]||=[O(` Support Center `,-1)]]),_:1})])])]),_(`li`,null,[_(`ul`,null,[_(`li`,{onClick:t},[A(a,{to:`/changelog`},{default:f(()=>[...i[45]||=[O(` Changelog `,-1)]]),_:1})])])])])]),_(`li`,yn,[i[97]||=_(`a`,{href:`#`,class:`parent`},` Components `,-1),_(`ul`,bn,[_(`li`,null,[i[58]||=_(`span`,{class:`mega-title`},`Components`,-1),_(`ul`,null,[_(`li`,{onClick:t},[A(a,{to:`/components/alerts`},{default:f(()=>[...i[47]||=[O(` Alert `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/avatar`},{default:f(()=>[...i[48]||=[O(` Avatar `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/badge`},{default:f(()=>[...i[49]||=[O(` Badge `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/breadcrumb`},{default:f(()=>[...i[50]||=[O(` Breadcrumb `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/button`},{default:f(()=>[...i[51]||=[O(` Buttons `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/calendar`},{default:f(()=>[...i[52]||=[O(` Calendar `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/cards`},{default:f(()=>[...i[53]||=[O(` Card `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/carousel`},{default:f(()=>[...i[54]||=[O(` Carousel `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/cascader`},{default:f(()=>[...i[55]||=[O(` Cascader `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/checkbox`},{default:f(()=>[...i[56]||=[O(` Checkbox `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/collapse`},{default:f(()=>[...i[57]||=[O(` Collapse `,-1)]]),_:1})])])]),_(`li`,null,[i[70]||=_(`span`,{class:`mega-title`},`Components`,-1),_(`ul`,null,[_(`li`,{onClick:t},[A(a,{to:`/components/comments`},{default:f(()=>[...i[59]||=[O(` Comments `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/dash-base`},{default:f(()=>[...i[60]||=[O(` Dashboard Base `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/datePicker`},{default:f(()=>[...i[61]||=[O(` DataPicker `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/drag-drop`},{default:f(()=>[...i[62]||=[O(` Drag & Drop `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/drawer`},{default:f(()=>[...i[63]||=[O(` Drawer `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/dropdown`},{default:f(()=>[...i[64]||=[O(` Dropdown `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/empty`},{default:f(()=>[...i[65]||=[O(` Empty `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/grid`},{default:f(()=>[...i[66]||=[O(` Grid `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/input`},{default:f(()=>[...i[67]||=[O(` Input `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/list`},{default:f(()=>[...i[68]||=[O(` List `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/menu`},{default:f(()=>[...i[69]||=[O(` Menu `,-1)]]),_:1})])])]),_(`li`,null,[i[83]||=_(`span`,{class:`mega-title`},`Components`,-1),_(`ul`,null,[_(`li`,{onClick:t},[A(a,{to:`/components/message`},{default:f(()=>[...i[71]||=[O(` Message `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/modal`},{default:f(()=>[...i[72]||=[O(` Modals `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/notification`},{default:f(()=>[...i[73]||=[O(` Notifications `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/pageHeader`},{default:f(()=>[...i[74]||=[O(` Page Headers `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/pagination`},{default:f(()=>[...i[75]||=[O(` Pagination `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/popConfirm`},{default:f(()=>[...i[76]||=[O(` PopConfirm `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/popover`},{default:f(()=>[...i[77]||=[O(` PopOver `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/progressbar`},{default:f(()=>[...i[78]||=[O(` Progress `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/radio`},{default:f(()=>[...i[79]||=[O(` Radio `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/rate`},{default:f(()=>[...i[80]||=[O(` Rate `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/result`},{default:f(()=>[...i[81]||=[O(` Result `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/select`},{default:f(()=>[...i[82]||=[O(` Select `,-1)]]),_:1})])])]),_(`li`,null,[i[96]||=_(`span`,{class:`mega-title`},`Components`,-1),_(`ul`,null,[_(`li`,{onClick:t},[A(a,{to:`/components/skeleton`},{default:f(()=>[...i[84]||=[O(` Skeleton `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/slider`},{default:f(()=>[...i[85]||=[O(` Slider `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/spiner`},{default:f(()=>[...i[86]||=[O(` Spiner `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/statistic`},{default:f(()=>[...i[87]||=[O(` Statistics `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/steps`},{default:f(()=>[...i[88]||=[O(` Steps `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/switch`},{default:f(()=>[...i[89]||=[O(` Switch `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/tabs`},{default:f(()=>[...i[90]||=[O(` Tabs `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/tags`},{default:f(()=>[...i[91]||=[O(` Tags `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/timeline`},{default:f(()=>[...i[92]||=[O(` Timeline `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/time-picker`},{default:f(()=>[...i[93]||=[O(` TimePicker `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/tree-select`},{default:f(()=>[...i[94]||=[O(` Tree Select `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/components/upload`},{default:f(()=>[...i[95]||=[O(` Upload `,-1)]]),_:1})])])])])]),_(`li`,xn,[i[129]||=_(`a`,{href:`#`,class:`parent`},` Features `,-1),_(`ul`,Sn,[_(`li`,Cn,[_(`a`,wn,[A(o,{name:`chart-bar`}),i[98]||=O(` Charts `,-1)]),_(`ul`,Tn,[_(`li`,{onClick:t},[A(a,{to:`/chart/chart-js`},{default:f(()=>[...i[99]||=[O(` Chart Js `,-1)]]),_:1})]),_(`li`,En,[i[107]||=_(`a`,{href:`#`},`Apex Charts`,-1),_(`ul`,Dn,[_(`li`,{onClick:t},[A(a,{to:`/chart/column-chart`},{default:f(()=>[...i[100]||=[O(` Column Charts `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/chart/line-chart`},{default:f(()=>[...i[101]||=[O(` Line Charts `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/chart/area-chart`},{default:f(()=>[...i[102]||=[O(` Area Charts `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/chart/bar-chart`},{default:f(()=>[...i[103]||=[O(` Bar Charts `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/chart/pie-chart`},{default:f(()=>[...i[104]||=[O(` Pie Charts `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/chart/radar-charts`},{default:f(()=>[...i[105]||=[O(` Radar Charts `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/chart/radialbar-chart`},{default:f(()=>[...i[106]||=[O(` Radialbar Charts `,-1)]]),_:1})])])])])]),_(`li`,On,[_(`a`,kn,[A(o,{name:`compact-disc`}),i[108]||=O(` Form `,-1)]),_(`ul`,An,[_(`li`,{onClick:t},[A(a,{to:`/forms/form-layout`},{default:f(()=>[...i[109]||=[O(` Form Layouts `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/forms/form-elements`},{default:f(()=>[...i[110]||=[O(` Form Elements `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/forms/form-components`},{default:f(()=>[...i[111]||=[O(` Form Components `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/forms/form-validation`},{default:f(()=>[...i[112]||=[O(` Form Validation `,-1)]]),_:1})])])]),_(`li`,jn,[_(`a`,Mn,[A(o,{name:`processor`}),i[113]||=O(` Tables `,-1)]),_(`ul`,Nn,[_(`li`,{onClick:t},[A(a,{to:`/tables/basic`},{default:f(()=>[...i[114]||=[O(` Basic Table `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/tables/dataTable`},{default:f(()=>[...i[115]||=[O(` Data Table `,-1)]]),_:1})])])]),_(`li`,Pn,[_(`a`,Fn,[A(o,{name:`server`}),i[116]||=O(` Widgets `,-1)]),_(`ul`,In,[_(`li`,{onClick:t},[A(a,{to:`/widgets/chart`},{default:f(()=>[...i[117]||=[O(` Chart `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/widgets/card`},{default:f(()=>[...i[118]||=[O(` Card `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/widgets/mixed`},{default:f(()=>[...i[119]||=[O(` Mixed `,-1)]]),_:1})])])]),_(`li`,Ln,[_(`a`,Rn,[A(o,{name:`server`}),i[120]||=O(` Wizards `,-1)]),_(`ul`,zn,[_(`li`,{onClick:t},[A(a,{to:`/wizard/wizard1`},{default:f(()=>[...i[121]||=[O(` Wizard 1 `,-1)]]),_:1})])])]),_(`li`,Bn,[_(`a`,Vn,[A(o,{name:`grid`}),i[122]||=O(` Icons `,-1)]),_(`ul`,Hn,[_(`li`,{onClick:t},[A(a,{to:`/icons/featherIcons`},{default:f(()=>[...i[123]||=[O(` Feather Icons(svg) `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/icons/fa`},{default:f(()=>[...i[124]||=[O(` Font Awesome `,-1)]]),_:1})])])]),_(`li`,Un,[_(`a`,Wn,[A(o,{name:`map`}),i[125]||=O(` Maps `,-1)]),_(`ul`,Gn,[_(`li`,{onClick:t},[A(a,{to:`/maps/google`},{default:f(()=>[...i[126]||=[O(` Google Maps `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/maps/leaflet`},{default:f(()=>[...i[127]||=[O(` Leaflet Maps `,-1)]]),_:1})]),_(`li`,{onClick:t},[A(a,{to:`/maps/Vector`},{default:f(()=>[...i[128]||=[O(` Vector Maps `,-1)]]),_:1})])])])])])])])]),_:1})}}}),qn={class:`ninjadash-header-content d-flex`},Jn={class:`ninjadash-header-content__left`},Yn={class:`navbar-brand align-cener-v`},Xn={key:0,src:me,alt:`CyberSathy`,style:{height:`55px`,width:`auto`}},Zn={key:1,src:_e,alt:`CyberSathy`,style:{height:`40px`,width:`auto`}},Qn={class:`ninjadash-header-launchers d-flex align-center-v`},$n={class:`ninjadash-header-content__right d-flex`},er={class:`ninjadash-navbar-menu d-flex align-center-v`},tr={class:`ninjadash-nav-actions`},nr={class:`top-right-wrap d-flex`},rr={class:`spin`},ir=L(m({__name:`AdminLayout`,setup(e){let{Header:t,Footer:i,Sider:o,Content:s}=I,c=p(!1),{dispatch:l,state:u}=le(),d=h(()=>u.themeLayout.rtlData),m=h(()=>u.themeLayout.data),y=h(()=>u.themeLayout.topMenu),b=window.innerWidth;c.value=window.innerWidth<=1200&&!0;let x=e=>{e.preventDefault(),c.value=!c.value},E=()=>{b<=990&&(c.value=!c.value)};b<=990&&document.body.addEventListener(`click`,e=>{!e.target.closest(`.ant-layout-sider`)&&!e.target.closest(`.navbar-brand .ant-btn`)&&(c.value=!0)});let D={onRtlChange:()=>{document.querySelector(`html`).setAttribute(`dir`,`rtl`),l(`changeRtlMode`,!0)},onLtrChange:()=>{document.querySelector(`html`).setAttribute(`dir`,`ltr`),l(`changeRtlMode`,!1)},modeChangeDark:()=>{l(`changeLayoutMode`,!0)},modeChangeLight:()=>{l(`changeLayoutMode`,!1)},modeChangeTopNav:()=>{l(`changeMenuMode`,!0)},modeChangeSideNav:()=>{l(`changeMenuMode`,!1)}};return(e,l)=>{let u=n(`router-link`),p=n(`sdButton`),h=n(`router-view`),O=ce,k=pe,j=oe;return r(),T(g(ye),{darkMode:m.value},{default:f(()=>[A(g(I),{class:`layout`},{default:f(()=>[A(g(t),{style:a({position:`fixed`,width:`100%`,top:0,[d.value?`right`:`left`]:0})},{default:f(()=>[_(`div`,qn,[_(`div`,Jn,[_(`div`,Yn,[A(u,{class:S(y.value&&g(b)>991?`ninjadash-logo top-menu`:`ninjadash-logo`),to:`/`},{default:f(()=>[c.value?(r(),v(`img`,Zn)):(r(),v(`img`,Xn))]),_:1},8,[`class`]),!y.value||g(b)<=991?(r(),T(p,{key:0,onClick:x,type:`white`},{default:f(()=>[...l[0]||=[_(`img`,{src:`data:image/svg+xml,%3csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%2024%2024'%3e%3cpath%20fill='%23525768'%20d='M5,8H19a1,1,0,0,0,0-2H5A1,1,0,0,0,5,8Zm16,3H3a1,1,0,0,0,0,2H21a1,1,0,0,0,0-2Zm-2,5H5a1,1,0,0,0,0,2H19a1,1,0,0,0,0-2Z'/%3e%3c/svg%3e`,alt:`menu`},null,-1)]]),_:1})):C(``,!0)])]),_(`div`,Qn,[A(Re),A(Qe)]),_(`div`,$n,[_(`div`,er,[y.value&&g(b)>991?(r(),T(Kn,{key:0})):C(``,!0)]),_(`div`,tr,[y.value&&g(b)>991?(r(),T(g(be),{key:0},{default:f(()=>[_(`div`,nr,[A(Kt)])]),_:1})):(r(),T(Kt,{key:1}))])])])]),_:1},8,[`style`]),A(g(I),null,{default:f(()=>[!y.value||g(b)<=991?(r(),T(g(o),{key:0,width:280,style:a({margin:`72px 0 0 0`,padding:`${d.value?`20px 0px 55px 20px`:`20px 20px 55px 0px`}`,overflowY:`auto`,height:`100vh`,position:`fixed`,[d.value?`right`:`left`]:0,zIndex:998}),collapsed:c.value,theme:m.value?`dark`:`light`},{default:f(()=>[A(g(xt),{options:{wheelSpeed:1,swipeEasing:!0,suppressScrollX:!0}},{default:f(()=>[A(qt,{toggleCollapsed:E,topMenu:y.value,rtl:d.value,darkMode:m.value,events:D},null,8,[`topMenu`,`rtl`,`darkMode`])]),_:1})]),_:1},8,[`style`,`collapsed`,`theme`])):C(``,!0),A(g(I),{class:`ninjadash-main-layout`},{default:f(()=>[A(g(s),null,{default:f(()=>[(r(),T(w,null,{default:f(()=>[A(h)]),fallback:f(()=>[_(`div`,rr,[A(O)])]),_:1})),A(g(i),{class:`admin-footer`,style:{padding:`20px 30px 18px`,color:`rgba(0, 0, 0, 0.65)`,fontSize:`14px`,background:`rgba(255, 255, 255, .90)`,width:`100%`,boxShadow:`0 -5px 10px rgba(146,153,184, 0.05)`}},{default:f(()=>[A(j,null,{default:f(()=>[A(k,{span:24},{default:f(()=>[...l[1]||=[_(`span`,{class:`admin-footer__copyright`},`© 2026 CyberSathy IT and Technology Pvt LTD`,-1)]]),_:1})]),_:1})]),_:1})]),_:1})]),_:1})]),_:1})]),_:1})]),_:1},8,[`darkMode`])}}}),[[`__scopeId`,`data-v-d8a46ca3`]]);export{ir as default};