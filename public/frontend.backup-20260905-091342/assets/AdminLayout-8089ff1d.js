import{s as W,d as Wt,a as q,w as pt,o as C,c as D,b as o,e as a,f as M,g as i,r as vt,h as mt,i as Xt,j as H,T as Dt,k as Pt,_ as V,u as dt,l as E,m as Mt,v as Ht,t as Y,F,n as ut,p as s,q as ft,x as R,D as st,S as gt,y as Nt,z as It,A as bt,B as N,C as Lt,E as S,G as Bt,H as ht,I as Ot,J as zt,K as qt,L as Kt,M as Ut,N as Ft,O as Vt,P as xt,Q as ot,R as Gt,U as Qt,V as Jt}from"./index-6e93aa06.js";/* empty css              */import{_ as Zt}from"./logo-cybersathy-full-4c8f1de4.js";/* empty css              */import{r as te}from"./relativeTime-2cc51a49.js";import{i as ee}from"./utilities-c04277ad.js";const ne="/assets/logo-cybersathy-icon-62fdc89b.png",G=Wt(),ct=W.p`
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    color: rgb(146, 153, 184);
    padding: 0px 15px;
    display: flex;
`,oe=W("div",G)`
    .ant-layout {
        background-color: transparent;
        .ant-layout-header{
            padding: ${({theme:t})=>t.rtl?"0 0 0 30px":"0 30px 0 0"};
            height: 72px;
            @media only screen and (max-width: 991px){
                padding: 0 15px;
            }
        }
    }
    .ant-layout.layout {
        background-color: ${({theme:t})=>t[t.mainContent]["main-background"]} !important;
    }

    .ninjadash-nav-actions__searchbar{
        display: flex;
        align-items: center;
        svg,
        img{
            width: 16px;
            height: 16px;
            color: ${({theme:t})=>t[t.mainContent]["light-text"]};
            fill: ${({theme:t})=>t[t.mainContent]["light-text"]};
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
                box-shadow: 0 5px 30px ${({theme:t})=>t["gray-solid"]}15;
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
            background-color: ${({theme:t})=>t[t.mainContent]["brand-background"]};
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

                    color: ${({theme:t})=>t[t.mainContent]["extra-light"]};
                    background-color: ${({theme:t})=>t[t.mainContent]["brand-background"]};
                    :hover {
                        background-color: ${({theme:t})=>t[t.mainContent]["brand-background"]} !important;
                    }

                    @media only screen and (max-width: 875px){
                        padding: ${({theme:t})=>t.rtl?"0 10px 0 20px":"0 20px 0 10px"};
                    }
                    @media only screen and (max-width: 767px){
                        order: -1;
                        padding: ${({theme:t})=>t.rtl?"0 0 0 15px":"0 15px 0 0"};
                    }
                }
            }
            .ninjadash-logo{
                @media only screen and (max-width: 875px){
                    ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 4px;
                }
                @media only screen and (max-width: 767px){
                    ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 0;
                }
                img{
                    max-width: ${({theme:t})=>t.topMenu?"140px":"120px"};
                    width: 100%;
                    @media only screen and (max-width: 475px){
                        max-width: ${({theme:t})=>(t.topMenu,"100px")};
                    }
                }
                &.top-menu{
                    ${({theme:t})=>t.rtl?"margin-right":"margin-left"}: 15px;
                }
            }
        }
        .ninjadash-header-content__right{
            flex: auto;
            svg{
                fill: ${({theme:t})=>t[t.mainContent]["light-text"]}};
            }
            .ninjadash_menu-item-icon {
                fill: transparent !important;
                stroke: ${({theme:t})=>t[t.mainContent]["light-text"]}};
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
                            color: ${({theme:t})=>t[t.mainContent]["light-text"]}};
                            fill: ${({theme:t})=>t[t.mainContent]["light-text"]}};
                        }
                        svg{
                            fill: ${({theme:t})=>t[t.mainContent]["light-text"]}};
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
                    margin: ${({theme:t})=>t.rtl?"0 10px 0 6px":"0 6px 0 10px"};
                    color: ${({theme:t})=>t[t.mainContent]["gray-text"]};
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
                ${({theme:t})=>t.rtl?"left":"right"}: 20px;
                top: 50%;
                transform: translateY(-50%);
                display: inline-flex;
                align-items: center;
                @media only screen and (max-width: 767px){
                    ${({theme:t})=>t.rtl?"left":"right"}: 15px;
                }
                a,
                .btn-search{
                    display: inline-flex;
                    color: ${({theme:t})=>t["light-color"]};
                    &.btn-search{
                        ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 18px;
                        @media only screen and (max-width: 475px){
                            ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 8px;
                        }
                    }
                    svg{
                        width: 18px;
                        height: 18px;
					    fill: ${({theme:t})=>t["light-color"]};
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
                    ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 5px;
                }
                svg{
                    width: 20px;
                    height: 20px;
                    fill: ${({theme:t})=>t[t.mainContent]["light-text"]}};
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
        box-shadow: 0 5px 20px ${({theme:t})=>t["extra-light-color"]}05;
        z-index: 998;
        background-color: ${({theme:t})=>t[t.mainContent]["white-background"]} !important;
        @media print {
            display: none;
        }
        .ant-menu-sub.ant-menu-vertical{
            .ant-menu-item{
                a{
                    color: ${({theme:t})=>t["gray-color"]}};
                }
            }
        }
        .ant-menu.ant-menu-horizontal{
            display: flex;
            align-items: center;
            margin: 0 -16px;
            background: ${({theme:t})=>t[t.mainContent]["main-background-light"]};
            border-bottom-color: ${({theme:t})=>t[t.mainContent]["border-color-default"]};
            li.ant-menu-submenu{
                margin: 0 16px;
            }
            .ant-menu-item{
                color: ${({theme:t})=>t[t.mainContent]["gray-text"]};
                &.ant-menu-item-disabled{
                    color: ${({theme:t})=>t[t.mainContent]["gray-light-text"]} !important;
                }
            }
            .ant-menu-submenu{
                &.ant-menu-submenu-active,
                &.ant-menu-submenu-selected,
                &.ant-menu-submenu-open{
                    .ant-menu-submenu-title{
                        color: ${({theme:t})=>t[t.mainContent]["dark-text"]};
                        svg,
                        i{
                            color: ${({theme:t})=>t[t.mainContent]["dark-text"]};
                            fill: ${({theme:t})=>t[t.mainContent]["dark-text"]};
                        }
                    }
                }
                .ant-menu-submenu-title{
                    font-size: 14px;
                    font-weight: 500;
                    color: ${({theme:t})=>t[t.mainContent]["dark-text"]};
                    svg,
                    i{
                        color: ${({theme:t})=>t[t.mainContent]["dark-text"]};
                        fill: ${({theme:t})=>t[t.mainContent]["dark-text"]};
                    }
                    .ant-menu-submenu-arrow{
                        font-family: "FontAwesome";
                        font-style: normal;
                        ${({theme:t})=>t.rtl?"margin-right":"margin-left"}: 6px;
                        &:after{
                            color: ${({theme:t})=>t[t.mainContent]["dark-text"]};
                            content: '\f107';
                            background-color: transparent;
                        }
                    }
                }
            }
        }
        .ant-menu.ant-menu-vertical{
            background: ${({theme:t})=>t[t.mainContent]["main-background-light"]};
            border-right-color: ${({theme:t})=>t[t.mainContent]["border-color-default"]};
            .ant-menu-item{
                color: ${({theme:t})=>t[t.mainContent]["gray-text"]};
                svg{
                    fill: ${({theme:t})=>t[t.mainContent]["gray-text"]};
                }
            }
        }
    }

    /* Sidebar styles */
    .ant-layout-sider {
        box-shadow: 0 0 20px ${({theme:t})=>t["extra-light-color"]}05;
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
                ${({theme:t})=>t.rtl?"margin-right":"margin-left"}: 80px;

            }
            .ant-menu-item{
                color: #333;
                .badge{
                    display: none;
                }
            }
        }

        &.ant-layout-sider-dark {
            background: ${({theme:t})=>t[t.mainContent]["white-background"]} !important;
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
                color: ${({theme:t})=>t[t.mainContent]["gray-text"]};
                padding: 0 ${({theme:t})=>t.rtl?"20px":"15px"};
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
                ${({theme:t})=>t.rtl?"border-left":"border-right"}: 0 none;
                background: ${({theme:t})=>t[t.mainContent]["white-background"]};
                &.ant-menu-dark, &.ant-menu-dark .ant-menu-sub, &.ant-menu-dark .ant-menu-inline.ant-menu-sub {
                    background-color: ${({theme:t})=>t[t.mainContent]["white-background"]} !important;

                }
                .ant-menu-sub.ant-menu-inline{
                    background-color: ${({theme:t})=>t[t.mainContent]["white-background"]} !important;
                }

                .ant-menu-submenu-selected{
                    color: ${({theme:t})=>t[t.mainContent]["light-text"]};
                }
                .ant-menu-submenu,
                .ant-menu-item{
                    ${({theme:t})=>t.rtl&&"padding-right: 5px;"};
                    &.ant-menu-item-selected{
                        border-radius: 0 25px 25px 0;
                        background-color: ${({theme:t})=>t["primary-color"]}15;
                        &:after{
                            content: none;
                        }
                    }
                    &.ant-menu-submenu-active{
                        >.ant-menu-submenu-title .ant-menu-title-content{
                            color: ${({theme:t})=>t[t.mainContent]["menu-active"]};
                        }
                        svg{
                            fill: ${({theme:t})=>t[t.mainContent]["menu-active"]};
                        }

                        >.ant-menu-submenu-title{
                            .ant-menu-submenu-arrow:before,
                            .ant-menu-submenu-arrow:after{
                                background-color: ${({theme:t})=>t[t.mainContent]["menu-active"]};
                            }
                        }
                    }
                    &.ant-menu-item-active{
                        .ant-menu-item-icon{
                            svg{
                                fill: ${({theme:t})=>t[t.mainContent]["menu-active"]};
                            }
                        }
                        svg{
                            fill: ${({theme:t})=>t[t.mainContent]["menu-active"]};
                        }
                        .ant-menu-title-content a{
                            color: ${({theme:t})=>t[t.mainContent]["menu-active"]} !important;
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
                        color: ${({theme:t})=>t[t.mainContent]["menu-icon-color"]};
                        fill: ${({theme:t})=>t[t.mainContent]["menu-icon-color"]};
                        transition: 0.3s ease;
                    }
                    span{
                        display: inline-block;
                        transition: 0.3s ease;
                    }
                    .ant-menu-title-content{
                        ${({theme:t})=>t.rtl?"padding-right":"padding-left"}: 16px;
                    }
                    &.ant-menu-submenu-selected{
                        svg{
                            fill: ${({theme:t})=>t["primary-color"]};
                        }
                        .ant-menu-title-content{
                            color: ${({theme:t})=>t["primary-color"]} !important;
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
                            fill: ${({theme:t})=>t["primary-color"]};
                        }
                        .ant-menu-title-content{
                            a{
                                color: ${({theme:t})=>t["primary-color"]} !important;
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
                                ${({theme:t})=>t.rtl?"left":"right"}: 45px;
                            }
                            span{
                                font-weight: 500;
                                color: ${({theme:t})=>t[t.mainContent]["menu-active"]};
                            }
                            svg,
                            i,
                            .ant-menu-submenu-arrow{
                                color: ${({theme:t})=>t[t.mainContent]["menu-active"]};
                                fill: ${({theme:t})=>t[t.mainContent]["menu-active"]};
                                &:after,
                                &:before{
                                    background-color: ${({theme:t})=>t[t.mainContent]["menu-active"]};
                                }
                            }
                        }
                        .ant-menu-sub{
                            .ant-menu-item{
                                &.ant-menu-item-selected{
                                    background-color: ${({theme:t})=>t["primary-color"]}15 !important;
                                    border-radius: ${({theme:t})=>t.rtl?"21px 0 0 21px":"0 21px 21px 0"};
                                    a{
                                        font-weight: 500;
                                        color: ${({theme:t})=>t[t.mainContent]["menu-active"]} !important;
                                    }
                                }
                            }
                        }
                    }
                    .ant-menu-submenu-title{
                        .ant-menu-title-content{
                            font-weight: 500;
                            color: ${({theme:t})=>t[t.mainContent]["gray-text"]};
                            text-align: ${({theme:t})=>t.rtl?"right":"left"};
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
                        color: ${({theme:t})=>t["gray-text"]};
                        position: relative;
                    }
                    >span{
                        width: 100%;
                        margin-left: 0;
                        .pl-0{
                            ${({theme:t})=>t.rtl?"padding-right":"padding-left"}: 0px;
                        }
                    }
                    .badge{
                        position: absolute;
                        ${({theme:t})=>t.rtl?"left":"right"}: 30px;
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
                            background-color: ${({theme:t})=>t["primary-color"]};
                        }
                        &.badge-success{
                            background-color: ${({theme:t})=>t["success-color"]};
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
                            ${({theme:t})=>t.rtl?"left":"right"}: 24px;
                            &:after,
                            &:before{
                                width: 6px;
                                background: #868EAE;
                                height: 1.2px;
                            }
                            &:before{
                                transform: rotate(45deg) ${({theme:t})=>t.rtl?"translateY(3px)":"translateY(-3px)"};
                            }
                            &:after{
                                transform: rotate(-45deg) ${({theme:t})=>t.rtl?"translateY(-3px)":"translateY(3px)"};
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
                        ${({theme:t})=>t.rtl?"padding-right":"padding-left"}: 0 !important;
                        ${({theme:t})=>t.rtl?"padding-left":"padding-right"}: 0 !important;
                        transition: all 0.2s cubic-bezier(0.215, 0.61, 0.355, 1) 0s;
                        a{
                            ${({theme:t})=>t.rtl?"padding-right":"padding-left"}: 36px !important;
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
                            color: ${({theme:t})=>t[t.mainContent]["menu-icon-color"]};
                        }
                        span{
                            ${({theme:t})=>t.rtl?"padding-right":"padding-left"}: 20px;
                            display: inline-block;
                            color: ${({theme:t})=>t["dark-color"]};
                        }
                    }
                    &.ant-menu-item-selected{
                        svg,
                        i{
                            color: ${({theme:t})=>t[t.mainContent]["menu-active"]};
                        }
                    }
                }


                &.ant-menu-inline-collapsed{
                    .ant-menu-submenu{
                        text-align: ${({theme:t})=>t.rtl?"right":"left"};
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
            ${({theme:t})=>t.rtl?"right":"left"}: -80px !important;
        }

    }

    .ninjadash-main-layout{
        ${({theme:t})=>t.rtl?"margin-right":"margin-left"}: ${({theme:t})=>t.topMenu?0:"280px"};
        margin-top: 64px;
        transition: 0.3s ease;

        @media only screen and (max-width: 1150px){
            ${({theme:t})=>t.rtl?"margin-right":"margin-left"}: auto !important;
        }
        @media print {
            width: 100%;
            margin-left: 0;
            margin-right: 0;
        }
    }
    .admin-footer{
        background-color: ${({theme:t})=>t[t.mainContent]["white-background"]} !important;
        @media print {
            display: none;
        }
        .admin-footer__copyright{
            display: inline-block;
            width: 100%;
            font-weight: 500;
            color: ${({theme:t})=>t[t.mainContent]["gray-text"]};
            @media only screen and (max-width: 767px){
                text-align: center;
                margin-bottom: 10px;
            }
            a{
                display: inline-block;
                margin-left: 4px;
                font-weight: 500;
                color: ${({theme:t})=>t[t.mainContent]["menu-active"]};
            }
        }
        .admin-footer__links{
            margin: 0 -9px;
            text-align: ${({theme:t})=>t.rtl?"left":"right"};
            @media only screen and (max-width: 767px){
                text-align: center;
            }
            a {
                margin: 0 9px;
                color: ${({theme:t})=>t[t.mainContent]["gray-text"]};
                &:hover{
                    color: ${({theme:t})=>t["primary-color"]};
                }
                &:not(:last-child) {
                    ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 15px;
                }
            }
        }
    }
    /* Common Styles */
    .ant-radio-button-wrapper-checked:not() {
        &:not(.ant-radio-button-wrapper-disabled){
            background: ${({theme:t})=>t[t.mainContent].white};
            color: ${({theme:t})=>t[t.mainContent]["menu-active"]};
        }
    }
`;W("div",G)`
        ${({darkMode:t})=>t?"background: #272B41;":"background: #fff"};
        width: 100%;
        position: fixed;
        margin-top: ${({hide:t})=>t?"0px":"64px"};
        top: 9px;
        ${({theme:t})=>t.rtl?"right":"left"}: 0;
        transition: .3s;
        opacity: ${({hide:t})=>t?0:1}
        z-index: ${({hide:t})=>t?-1:1}
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
`;W("div",G)`
        ${({darkMode:t})=>t?"background: #272B41;":"background: #fff"};
        width: 100%;
        position: fixed;
        margin-top: ${({hide:t})=>t?"0px":"64px"};
        top: 0;
        ${({theme:t})=>t.rtl?"right":"left"}: 0;
        transition: .3s;
        opacity: ${({hide:t})=>t?0:1}
        z-index: ${({hide:t})=>t?-1:999}
        box-shadow: 0 2px 30px #9299b810;
`;W("div",G)`
    background: #ddd;
    width: 200px;
    position: fixed;
    ${({theme:t})=>t.rtl?"left":"right"}: 0;
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
`;const ie=W("div",G)`
    .top-right-wrap{
        position: relative;
        float: ${({theme:t})=>t.rtl?"left":"right"};
    }
    .search-toggle{
        display: flex;
        align-items: center;
        ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 10px;
        ${({theme:t})=>t.darkMode?"color: #A8AAB3;":"color :#5A5F7D"};
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
        ${({theme:t})=>t.rtl?"left":"right"}: 100%;
        ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 15px;
        top: 12px;
        background-color: #fff;
        border: 1px solid ${({theme:t})=>t["border-color-normal"]};
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
            ${({theme:t})=>t.rtl?"right":"left"}: 15px;
            top: 50%;
            transform: translateY(-50%);
            z-index: 9999;
        }
        i,
        svg{
            width: 18px;
            fill: ${({theme:t})=>t.darkMode?"color: #A8AAB3;":"color:# 9299b8"};
        }
        svg{
            fill: ${({theme:t})=>t.darkMode?"color: #A8AAB3;":"color:# 9299b8"};
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
            ${({theme:t})=>t.rtl?"padding-right":"padding-left"}: 40px;
            &:focus{
                border: 0 none;
                box-shadow: 0 0;
                outline: none;
            }
        }
    }
`,ae=W("div",G)`
.ninjadash-top-menu{
    ul{
        margin-bottom: 0;
        li{
            display: inline-block;
            position: relative;
            ${({theme:t})=>t.rtl?"padding-left":"padding-right"}: 14px;
            @media only screen and (max-width: 1024px){
                ${({theme:t})=>t.rtl?"padding-left":"padding-right"}: 10px;
            }
            &:not(:last-child){
                ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 34px;
                @media only screen and (max-width: 1399px){
                    ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 30px;
                }
                @media only screen and (max-width: 1199px){
                    ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 26px;
                }
                @media only screen and (max-width: 1024px){
                    ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 16px;
                }
            }
            .parent.active{
                color: ${({theme:t})=>t[t.mainContent]["menu-active"]};
            }
            &.has-subMenu{
                >a{
                    position: relative;
                    &:before{
                        position: absolute;
                        ${({theme:t})=>t.rtl?"left":"right"}: -14px;
                        top: 50%;
                        transform: translateY(-50%);
                        font-family: "FontAwesome";
                        content: '\f107';
                        line-height: 1;
                        color: ${({theme:t})=>t[t.mainContent]["light-text"]};
                    }
                    &.active{
                        &:before{
                            color: ${({theme:t})=>t[t.mainContent]["menu-active"]};
                        }
                    }
                }
            }
            &.has-subMenu-left{
                >a{
                    position: relative;
                    &:before{
                        position: absolute;
                        ${({theme:t})=>t.rtl?"left":"right"}: 30px;
                        top: 50%;
                        transform: translateY(-50%);
                        font-family: "FontAwesome";
                        content: '\f105';
                        line-height: 1;
                        color: ${({theme:t})=>t[t.mainContent]["light-text"]};
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
                color: ${({theme:t})=>t[t.mainContent]["light-text"]};
                &.active{
                    color: ${({theme:t})=>t[t.mainContent]["light-text"]};
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
                    ${({theme:t})=>t.rtl?"padding-left":"padding-right"}: 0;
                    ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 0 !important;
                    a{
                        font-weight: 400;
                        padding: 0 30px;
                        line-height: 3;
                        color: #868EAE;
                        transition: .3s;
                        &:hover,
                        &[aria-current="page"]{
                            color: ${({theme:t})=>t[t.mainContent]["menu-active"]};
                            background-color: ${({theme:t})=>t[t.mainContent]["menu-active"]}06;
                            ${({theme:t})=>t.rtl?"padding-right":"padding-left"}: 40px;
                        }
                    }
                    &:hover{
                        .subMenu{
                            top: 0;
                            ${({theme:t})=>t.rtl?"right":"left"}: 250px;
                            @media only screen and (max-width: 1300px){
                                ${({theme:t})=>t.rtl?"right":"left"}: 180px;
                            }
                        }
                    }
                }
            }
        }
    }
    .subMenu{
        width: 250px;
        background: ${({theme:t})=>t[t.mainContent]["white-background"]};
        border-radius: 6px;
        position: absolute;
        ${({theme:t})=>t.rtl?"right":"left"}: 0;
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
            background:${({theme:t})=>t[t.mainContent]["white-background"]};
            position: absolute;
            ${({theme:t})=>t.rtl?"right":"left"}: 250px;
            top: 0px;
            padding: 12px 0;
            visibility: hidden;
            opacity: 0;
            transition: 0.3s;
            z-index: 98;
            box-shadow: 0px 15px 40px 0px rgba(82, 63, 105, 0.15);
            @media only screen and (max-width: 1300px){
                width: 200px;
                ${({theme:t})=>t.rtl?"right":"left"}: 180px;
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
                text-align: ${({theme:t})=>t.rtl?"right":"left"}
                ${({theme:t})=>t.rtl?"right":"left"}: 0;
                top: 100%;
                overflow: hidden;
                z-index: -1;
                padding: 16px 0;
                box-shadow: 0px 15px 40px 0px rgba(82, 63, 105, 0.15);
                border-radius: 0 0 6px 6px;
                opacity: 0;
                visibility: hidden;
                transition: .4s;
                background-color: ${({theme:t})=>t[t.mainContent]["white-background"]};
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
                                    ${({theme:t})=>t.rtl?"right":"left"}: 20px;
                                    top: 50%;
                                    transform: translateY(-50%);
                                    background-color: #C6D0DC;
                                    content: '';
                                    transition: .3s;
                                }
                                &:hover,
                                &[aria-current="page"]{
                                    ${({theme:t})=>t.rtl?"padding-right":"padding-left"}: 35px;
                                    color: ${({theme:t})=>t["primary-color"]};
                                    &:after{
                                        background-color: ${({theme:t})=>t["primary-color"]};;
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
                            ${({theme:t})=>t.rtl?"padding-right":"padding-left"}: 45px;
                            color: ${({theme:t})=>t[t.mainContent]["dark-text"]};
                            &:after{
                                position: absolute;
                                height: 5px;
                                width: 5px;
                                border-radius: 50%;
                                ${({theme:t})=>t.rtl?"right":"left"}: 30px;
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
                            ${({theme:t})=>t.rtl?"right":"left"}: 20px;
                            top: 50%;
                            transform: translateY(-50%);
                            background-color: ${({theme:t})=>t[t.mainContent]["gray-light-text"]};
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
`;W.aside`
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
`;W.div`
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
`;W.div`
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
`;const le=["aria-label"],se={class:"hqm-panel__header"},re={class:"hqm-panel__body"},de=q({__name:"HeaderQuickModal",props:{modelValue:{type:Boolean},ariaLabel:{}},emits:["update:modelValue","opened"],setup(t,{emit:e}){const d=t,n=e;function l(){n("update:modelValue",!1)}return pt(()=>d.modelValue,u=>{document.body.style.overflow=u?"hidden":"",u&&n("opened")}),(u,g)=>(C(),D(Pt,{to:"body"},[o(Dt,{name:"hqm-fade"},{default:a(()=>[t.modelValue?(C(),M("div",{key:0,class:"hqm-backdrop",onClick:mt(l,["self"]),onKeydown:Xt(l,["esc"])},[i("section",{class:"hqm-panel",role:"dialog","aria-modal":"true","aria-label":t.ariaLabel},[i("div",se,[vt(u.$slots,"header",{close:l},void 0,!0)]),i("div",re,[vt(u.$slots,"default",{close:l},void 0,!0)])],8,le)],32)):H("",!0)]),_:3})]))}});const St=V(de,[["__scopeId","data-v-5743a0b5"]]),ue={class:"ps-input-wrap"},ce=["onClick"],pe={class:"ps-results"},me={key:0,class:"ps-state"},fe={key:1,class:"ps-state"},ge={key:2,class:"ps-state"},he={key:3,class:"ps-state"},ve=["onClick"],be={class:"ps-result-icon"},xe={class:"ps-result-content"},ye={class:"ps-result-title"},ke={class:"ps-result-subtitle"},_e=q({__name:"PortalSearch",setup(t){const e=R(!1),d=R(""),n=R([]),l=R(!1),u=R(!1),g=R(null),h=dt();let p=null;const v={ONLINE:{label:"Up",class:"ok"},OFFLINE:{label:"Down",class:"down"},DISABLED:{label:"Disabled",class:"muted"},ERROR:{label:"Error",class:"down"},UNKNOWN:{label:"Unknown",class:"muted"}},_={device:"server-network",interface:"wifi-router",description:"comment-alt-message",agreement:"tag-alt",ont_ident:"wifi",fdb_history:"history",tags:"tag-alt"};function f(x){return x.type==="ont_ident"||x.type==="fdb_history"||x.type==="tags"?x.data.interface:x.data}function y(x){const k=x.data;switch(x.type){case"device":return`Device: ${k.name||k.ip}`;case"description":return`Description: ${k.description||"—"}`;case"agreement":return`Agreement: ${k.agreement||"—"}`;case"interface":return`Interface: ${k.name||"—"}`;case"ont_ident":return`ONT ident: ${k.ident||k.serial||k.value||"—"}`;case"fdb_history":return`MAC: ${k.mac_address||"—"}`;case"tags":return`Tag: ${k.tags||"—"}`;default:return"Result"}}function w(x){var O,J,et;const k=f(x);if(x.type==="device"){const nt=[(O=m(x).model)==null?void 0:O.vendor,((J=m(x).model)==null?void 0:J.model)||((et=m(x).model)==null?void 0:et.name)].filter(Boolean).join(" ");return nt?`Model: ${nt}`:m(x).ip||""}const L=k==null?void 0:k.device,P=L?` on device ${L.name||L.ip} (${L.ip||""})`:"";return`${x.type==="fdb_history"&&x.data.vlan_id!=null?`VLAN ${x.data.vlan_id} · `:""}Interface: ${(k==null?void 0:k.name)||"—"}${P}`}function m(x){return x.data}function r(x){const k=f(x);return k!=null&&k.status?v[k.status]:null}function c(x){var P,A;const k=x.type==="device"?x.data:(P=f(x))==null?void 0:P.device,L=x.type==="device"?x.data.id:((A=f(x))==null?void 0:A.device_id)||(k==null?void 0:k.id);L&&h.push({name:"device-detail",params:{id:L}}),e.value=!1}async function b(){const x=d.value.trim();if(x.length<3){n.value=[],u.value=!1;return}l.value=!0;try{const{data:k}=await st.get("/portal/search",{query:x});n.value=k.data||[]}catch{n.value=[]}finally{l.value=!1,u.value=!0}}pt(d,()=>{p&&clearTimeout(p),p=setTimeout(b,350)});function $(){d.value="",n.value=[],u.value=!1,setTimeout(()=>{var x;return(x=g.value)==null?void 0:x.focus()},50)}return(x,k)=>{const L=E("unicon"),P=gt;return C(),M(F,null,[i("a",{href:"#",class:"header-launcher-btn",title:"Search",onClick:k[0]||(k[0]=mt(A=>e.value=!0,["prevent"]))},[o(L,{name:"search"})]),o(St,{modelValue:e.value,"onUpdate:modelValue":k[2]||(k[2]=A=>e.value=A),"aria-label":"Search",onOpened:$},{header:a(({close:A})=>[i("div",ue,[o(L,{name:"search"}),Mt(i("input",{ref_key:"inputRef",ref:g,"onUpdate:modelValue":k[1]||(k[1]=O=>d.value=O),type:"search",class:"ps-input",autocomplete:"off",placeholder:"Searching..."},null,512),[[Ht,d.value]]),i("button",{type:"button",class:"ps-close","aria-label":"Close",onClick:A},[o(L,{name:"times"})],8,ce)])]),default:a(()=>[i("div",pe,[l.value?(C(),M("div",me,[o(P,{size:"small"}),k[3]||(k[3]=i("span",null,"Searching…",-1))])):d.value.trim().length>0&&d.value.trim().length<3?(C(),M("div",fe,[o(L,{name:"keyboard"}),i("span",null,"Type more "+Y(3-d.value.trim().length)+" symbols for start searching...",1)])):u.value&&n.value.length===0?(C(),M("div",ge,[o(L,{name:"search-alt"}),i("span",null,'No matches for "'+Y(d.value)+'"',1)])):d.value?H("",!0):(C(),M("div",he,[o(L,{name:"keyboard"}),k[4]||(k[4]=i("span",null,"Type more 3 symbols for start searching...",-1))])),(C(!0),M(F,null,ut(n.value,(A,O)=>(C(),M("button",{key:O,type:"button",class:"ps-result-row",onClick:J=>c(A)},[i("span",be,[o(L,{name:_[A.type]||"search"},null,8,["name"])]),i("span",xe,[i("span",ye,[s(Y(y(A))+" ",1),r(A)?(C(),M("span",{key:0,class:ft(["ps-badge",r(A).class])},Y(r(A).label),3)):H("",!0)]),i("span",ke,Y(w(A)),1)])],8,ve))),128))])]),_:1},8,["modelValue"])],64)}}});const we=V(_e,[["__scopeId","data-v-9553d706"]]),Ce={class:"no-header"},$e=["onClick"],Me={class:"no-results"},Le={key:0,class:"no-state"},Se={key:1,class:"no-state error"},je={key:2,class:"no-state"},Te=["onClick"],Ye={class:"no-result-icon"},Ae={class:"no-result-content"},Re={class:"no-result-title"},Ee={class:"no-result-subtitle"},We={class:"no-result-distance"},Xe=500,De=q({__name:"NearbyObjects",setup(t){const e=R(!1),d=R("all"),n=R(!1),l=R(""),u=R([]),g=dt(),h={device:"server-network",interface:"wifi-router",box:"box"};function p(m){const r=m.data;return m.type==="device"?r.name||r.ip||"Device":m.type==="interface"?r.name||"Interface":r.name||r.title||"Box"}function v(m){var c,b,$;const r=m.data;if(m.type==="device")return[(c=r.model)==null?void 0:c.vendor,((b=r.model)==null?void 0:b.model)||(($=r.model)==null?void 0:$.name)].filter(Boolean).join(" ")||r.ip||"";if(m.type==="interface"){const x=r.device;return x?`on device ${x.name||x.ip}`:""}return r.description||""}function _(m){return m==null?"":m<1e3?`${Math.round(m)} m away`:`${(m/1e3).toFixed(2)} km away`}function f(m){var c;const r=m.type==="device"?m.data.id:m.data.device_id||((c=m.data.device)==null?void 0:c.id);r&&g.push({name:"device-detail",params:{id:r}}),e.value=!1}function y(){if(l.value="",!navigator.geolocation){l.value="Geolocation is not supported by this browser";return}n.value=!0,navigator.geolocation.getCurrentPosition(async m=>{try{const{data:r}=await st.get("/portal/nearest-elements",{lat:m.coords.latitude,lon:m.coords.longitude,distance:Xe,...d.value!=="all"?{filter:d.value}:{}});u.value=r.data||[]}catch{l.value="Could not load nearby objects — please try again."}finally{n.value=!1}},()=>{l.value="Location access denied",n.value=!1},{enableHighAccuracy:!1,timeout:8e3})}pt(d,()=>{e.value&&y()});function w(){u.value=[],l.value="",y()}return(m,r)=>{const c=E("unicon"),b=gt,$=E("sdButton");return C(),M(F,null,[i("a",{href:"#",class:"header-launcher-btn",title:"Nearby objects",onClick:r[0]||(r[0]=mt(x=>e.value=!0,["prevent"]))},[o(c,{name:"crosshair"})]),o(St,{modelValue:e.value,"onUpdate:modelValue":r[2]||(r[2]=x=>e.value=x),"aria-label":"Nearby objects",onOpened:w},{header:a(({close:x})=>[i("div",Ce,[o(c,{name:"crosshair"}),r[4]||(r[4]=i("span",{class:"no-title"},"Nearby objects",-1)),Mt(i("select",{"onUpdate:modelValue":r[1]||(r[1]=k=>d.value=k),class:"no-filter","aria-label":"Object type"},[...r[3]||(r[3]=[i("option",{value:"all"},"All objects",-1),i("option",{value:"device"},"Device",-1),i("option",{value:"interface"},"Interface",-1),i("option",{value:"box"},"Boxes",-1)])],512),[[Nt,d.value]]),i("button",{type:"button",class:"no-close","aria-label":"Close",onClick:x},[o(c,{name:"times"})],8,$e)])]),default:a(()=>[i("div",Me,[n.value?(C(),M("div",Le,[o(b,{size:"small"}),r[5]||(r[5]=i("span",null,"Locating…",-1))])):l.value?(C(),M("div",Se,[o(c,{name:"exclamation-circle"}),i("span",null,Y(l.value),1),o($,{size:"small",type:"primary",onClick:y},{default:a(()=>[...r[6]||(r[6]=[s("Retry",-1)])]),_:1})])):u.value.length===0?(C(),M("div",je,[o(c,{name:"map-marker-alt"}),r[7]||(r[7]=i("span",null,"No objects found nearby.",-1))])):H("",!0),(C(!0),M(F,null,ut(u.value,(x,k)=>(C(),M("button",{key:k,type:"button",class:"no-result-row",onClick:L=>f(x)},[i("span",Ye,[o(c,{name:h[x.type]||"map-marker-alt"},null,8,["name"])]),i("span",Ae,[i("span",Re,Y(p(x)),1),i("span",Ee,Y(v(x)),1)]),i("span",We,Y(_(x.distance_m)),1)],8,Te))),128))])]),_:1},8,["modelValue"])],64)}}});const Pe=V(De,[["__scopeId","data-v-70a7cfa0"]]),He=["darkMode"],Ne=W("div",He)`
    display: flex;
    justify-content: flex-end;
    align-items: center;
    .ninjadash-nav-action-link{
        text-decoration: none;
        color: ${({theme:t})=>t[t.mainContent].secondary};
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
					fill: ${({theme:t})=>t[t.mainContent]["light-text"]};
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
                fill: ${({theme:t})=>t["gray-color"]};
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
                ${({theme:t})=>t.rtl?"left":"right"}: 11px !important;
            }
        }
        &.ninjadash-nav-actions__message{
            .ant-badge{
                .ant-badge-dot{
                    background: ${({theme:t})=>t[t.mainContent].success};
                }
            }
        }
        svg{
            fill: ${({theme:t})=>t[t.mainContent]["gray-text"]};
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
            box-shadow: 0 5px 30px ${({theme:t})=>t["gray-solid"]}15;
            li{
                &:first-child{
                    margin-top: 12px;
                }
                &:hover{
                    background: ${({theme:t})=>t["primary-color"]}05;
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
                        color: ${({theme:t})=>t["gray-color"]};
                        padding: 0;
                        margin-left: 10px;
                    }
                }
            }
        }
    }
`;W.div`
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
                box-shadow: 0 5px 20px ${({theme:t})=>t["gray-solid"]}15;
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
				color: ${({theme:t})=>t[t.mainContent]["dark-text"]};
            }
            p{
                margin-bottom: 0;
                color: ${({theme:t})=>t["gray-solid"]};
            }
            img{
                ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 16px;
                transform: ${({theme:t})=>t.rtl?"rotatey(180deg)":"rotatey(0deg)"};
            }
            figcaption{
                text-align: ${({theme:t})=>t.rtl?"right":"left"}
            }
        }
    }
`;W.div`
    .support-dropdown{
        padding: 10px 15px;
        text-align: ${({theme:t})=>t.rtl?"right":"left"};
        ul{
            &:not(:last-child){
                margin-bottom: 16px;
            }
            h1{
                font-size: 14px;
                font-weight: 400;
                color: ${({theme:t})=>t[t.mainContent]["light-text"]};
            }
            li{
                a{
                    font-weight: 500;
                    padding: 4px 16px;
                    color: ${({theme:t})=>t[t.mainContent]["dark-text"]};
                    &:hover{
                        background: #fff;
                        color: ${({theme:t})=>t["primary-color"]};
                    }
                }
            }
        }
    }
`;W.div`
    .user-dropdown{
        max-width: 280px;
        .user-dropdown__info{
            display: flex;
            align-items: flex-start;
            padding: 20px 25px;
            border-radius: 8px;
            margin-bottom: 12px;
            background: ${({theme:t})=>t[t.mainContent]["general-background"]};
            img{
                ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 15px;
            }
            figcaption{
                h1{
                    font-size: 14px;
                    margin-bottom: 2px;
                    color:  ${({theme:t})=>t[t.mainContent]["dark-text"]};
                }
                p{
                    margin-bottom: 0px;
                    font-size: 13px;
                    color: ${({theme:t})=>t[t.mainContent]["gray-text"]};
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
                color: ${({theme:t})=>t[t.mainContent]["gray-light-text"]};
                &:hover{
                    background: ${({theme:t})=>t["primary-color"]}05;
                    color: ${({theme:t})=>t[t.mainContent]["menu-active"]};
                    ${({theme:t})=>t.rtl?"padding-right":"padding-left"}: 22px;
                    svg{
                        fill: ${({theme:t})=>t["primary-color"]};
                    }
                }
                svg{
                    width: 16px;
                    transform: ${({theme:t})=>t.rtl?"rotateY(180deg)":"rotateY(0deg)"};
                    ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 14px;
					fill: ${({theme:t})=>t[t.mainContent]["light-text"]};
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
            background: ${({theme:t})=>t[t.mainContent]["general-background"]};
            color: ${({theme:t})=>t[t.mainContent]["light-text"]};
            svg{
                width: 15px;
                height: 15px;
                ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 8px;
            }
        }
    }
`;const Ie=W.div`
    .ninjadash-top-dropdown__title .title-text {
        ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 10px;
		color: ${({theme:t})=>t[t.mainContent]["dark-color"]};
    }
    .ninjadash-top-dropdown__content {
        figcaption{
            h1{
                color: ${({theme:t})=>t[t.mainContent]["dark-color"]};
            }
            .ninjadash-top-dropdownText{
                min-width: 216px;
                ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 15px;
            }
            span{
                ${({theme:t})=>t.rtl?"padding-right":"padding-left"}: 0;
            }
        }
        .notification-icon{
            width: 39.2px;
            height: 32px;
            ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 15px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            &.bg-primary{
                background: ${({theme:t})=>t["primary-color"]}15;
                svg{
                    fill: ${({theme:t})=>t["primary-color"]};
                }
            }
            &.bg-secondary{
                background: ${({theme:t})=>t["secondary-color"]}15;
                svg{
                    fill: ${({theme:t})=>t["secondary-color"]};
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
        ${({theme:t})=>t.rtl?"padding-right":"padding-left"}: 0;
    }

    .notification-text p {
        font-size: 12px;
        color: #ADB4D2;
        margin-bottom: 0;
        text-align: ${({theme:t})=>t.rtl?"right":"left"}
    }
`;W.span`
    i, svg, img {
        ${({theme:t})=>t.rtl?"margin-left":"margin-right"}: 8px;
    }
`;/*!
 * perfect-scrollbar v1.5.6
 * Copyright 2024 Hyunje Jun, MDBootstrap and Contributors
 * Licensed under MIT
 */function I(t){return getComputedStyle(t)}function X(t,e){for(var d in e){var n=e[d];typeof n=="number"&&(n=n+"px"),t.style[d]=n}return t}function it(t){var e=document.createElement("div");return e.className=t,e}var yt=typeof Element<"u"&&(Element.prototype.matches||Element.prototype.webkitMatchesSelector||Element.prototype.mozMatchesSelector||Element.prototype.msMatchesSelector);function z(t,e){if(!yt)throw new Error("No element matching method supported");return yt.call(t,e)}function U(t){t.remove?t.remove():t.parentNode&&t.parentNode.removeChild(t)}function kt(t,e){return Array.prototype.filter.call(t.children,function(d){return z(d,e)})}var T={main:"ps",rtl:"ps__rtl",element:{thumb:function(t){return"ps__thumb-"+t},rail:function(t){return"ps__rail-"+t},consuming:"ps__child--consume"},state:{focus:"ps--focus",clicking:"ps--clicking",active:function(t){return"ps--active-"+t},scrolling:function(t){return"ps--scrolling-"+t}}},jt={x:null,y:null};function Tt(t,e){var d=t.element.classList,n=T.state.scrolling(e);d.contains(n)?clearTimeout(jt[e]):d.add(n)}function Yt(t,e){jt[e]=setTimeout(function(){return t.isAlive&&t.element.classList.remove(T.state.scrolling(e))},t.settings.scrollingThreshold)}function Be(t,e){Tt(t,e),Yt(t,e)}var Z=function(e){this.element=e,this.handlers={}},At={isEmpty:{configurable:!0}};Z.prototype.bind=function(e,d){typeof this.handlers[e]>"u"&&(this.handlers[e]=[]),this.handlers[e].push(d),this.element.addEventListener(e,d,!1)};Z.prototype.unbind=function(e,d){var n=this;this.handlers[e]=this.handlers[e].filter(function(l){return d&&l!==d?!0:(n.element.removeEventListener(e,l,!1),!1)})};Z.prototype.unbindAll=function(){for(var e in this.handlers)this.unbind(e)};At.isEmpty.get=function(){var t=this;return Object.keys(this.handlers).every(function(e){return t.handlers[e].length===0})};Object.defineProperties(Z.prototype,At);var Q=function(){this.eventElements=[]};Q.prototype.eventElement=function(e){var d=this.eventElements.filter(function(n){return n.element===e})[0];return d||(d=new Z(e),this.eventElements.push(d)),d};Q.prototype.bind=function(e,d,n){this.eventElement(e).bind(d,n)};Q.prototype.unbind=function(e,d,n){var l=this.eventElement(e);l.unbind(d,n),l.isEmpty&&this.eventElements.splice(this.eventElements.indexOf(l),1)};Q.prototype.unbindAll=function(){this.eventElements.forEach(function(e){return e.unbindAll()}),this.eventElements=[]};Q.prototype.once=function(e,d,n){var l=this.eventElement(e),u=function(g){l.unbind(d,u),n(g)};l.bind(d,u)};function at(t){if(typeof window.CustomEvent=="function")return new CustomEvent(t);var e=document.createEvent("CustomEvent");return e.initCustomEvent(t,!1,!1,void 0),e}function rt(t,e,d,n,l){n===void 0&&(n=!0),l===void 0&&(l=!1);var u;if(e==="top")u=["contentHeight","containerHeight","scrollTop","y","up","down"];else if(e==="left")u=["contentWidth","containerWidth","scrollLeft","x","left","right"];else throw new Error("A proper axis should be provided");Oe(t,d,u,n,l)}function Oe(t,e,d,n,l){var u=d[0],g=d[1],h=d[2],p=d[3],v=d[4],_=d[5];n===void 0&&(n=!0),l===void 0&&(l=!1);var f=t.element;t.reach[p]=null,f[h]<1&&(t.reach[p]="start"),f[h]>t[u]-t[g]-1&&(t.reach[p]="end"),e&&(f.dispatchEvent(at("ps-scroll-"+p)),e<0?f.dispatchEvent(at("ps-scroll-"+v)):e>0&&f.dispatchEvent(at("ps-scroll-"+_)),n&&Be(t,p)),t.reach[p]&&(e||l)&&f.dispatchEvent(at("ps-"+p+"-reach-"+t.reach[p]))}function j(t){return parseInt(t,10)||0}function ze(t){return z(t,"input,[contenteditable]")||z(t,"select,[contenteditable]")||z(t,"textarea,[contenteditable]")||z(t,"button,[contenteditable]")}function qe(t){var e=I(t);return j(e.width)+j(e.paddingLeft)+j(e.paddingRight)+j(e.borderLeftWidth)+j(e.borderRightWidth)}var K={isWebKit:typeof document<"u"&&"WebkitAppearance"in document.documentElement.style,supportsTouch:typeof window<"u"&&("ontouchstart"in window||"maxTouchPoints"in window.navigator&&window.navigator.maxTouchPoints>0||window.DocumentTouch&&document instanceof window.DocumentTouch),supportsIePointer:typeof navigator<"u"&&navigator.msMaxTouchPoints,isChrome:typeof navigator<"u"&&/Chrome/i.test(navigator&&navigator.userAgent)};function B(t){var e=t.element,d=Math.floor(e.scrollTop),n=e.getBoundingClientRect();t.containerWidth=Math.floor(n.width),t.containerHeight=Math.floor(n.height),t.contentWidth=e.scrollWidth,t.contentHeight=e.scrollHeight,e.contains(t.scrollbarXRail)||(kt(e,T.element.rail("x")).forEach(function(l){return U(l)}),e.appendChild(t.scrollbarXRail)),e.contains(t.scrollbarYRail)||(kt(e,T.element.rail("y")).forEach(function(l){return U(l)}),e.appendChild(t.scrollbarYRail)),!t.settings.suppressScrollX&&t.containerWidth+t.settings.scrollXMarginOffset<t.contentWidth?(t.scrollbarXActive=!0,t.railXWidth=t.containerWidth-t.railXMarginWidth,t.railXRatio=t.containerWidth/t.railXWidth,t.scrollbarXWidth=_t(t,j(t.railXWidth*t.containerWidth/t.contentWidth)),t.scrollbarXLeft=j((t.negativeScrollAdjustment+e.scrollLeft)*(t.railXWidth-t.scrollbarXWidth)/(t.contentWidth-t.containerWidth))):t.scrollbarXActive=!1,!t.settings.suppressScrollY&&t.containerHeight+t.settings.scrollYMarginOffset<t.contentHeight?(t.scrollbarYActive=!0,t.railYHeight=t.containerHeight-t.railYMarginHeight,t.railYRatio=t.containerHeight/t.railYHeight,t.scrollbarYHeight=_t(t,j(t.railYHeight*t.containerHeight/t.contentHeight)),t.scrollbarYTop=j(d*(t.railYHeight-t.scrollbarYHeight)/(t.contentHeight-t.containerHeight))):t.scrollbarYActive=!1,t.scrollbarXLeft>=t.railXWidth-t.scrollbarXWidth&&(t.scrollbarXLeft=t.railXWidth-t.scrollbarXWidth),t.scrollbarYTop>=t.railYHeight-t.scrollbarYHeight&&(t.scrollbarYTop=t.railYHeight-t.scrollbarYHeight),Ke(e,t),t.scrollbarXActive?e.classList.add(T.state.active("x")):(e.classList.remove(T.state.active("x")),t.scrollbarXWidth=0,t.scrollbarXLeft=0,e.scrollLeft=t.isRtl===!0?t.contentWidth:0),t.scrollbarYActive?e.classList.add(T.state.active("y")):(e.classList.remove(T.state.active("y")),t.scrollbarYHeight=0,t.scrollbarYTop=0,e.scrollTop=0)}function _t(t,e){return t.settings.minScrollbarLength&&(e=Math.max(e,t.settings.minScrollbarLength)),t.settings.maxScrollbarLength&&(e=Math.min(e,t.settings.maxScrollbarLength)),e}function Ke(t,e){var d={width:e.railXWidth},n=Math.floor(t.scrollTop);e.isRtl?d.left=e.negativeScrollAdjustment+t.scrollLeft+e.containerWidth-e.contentWidth:d.left=t.scrollLeft,e.isScrollbarXUsingBottom?d.bottom=e.scrollbarXBottom-n:d.top=e.scrollbarXTop+n,X(e.scrollbarXRail,d);var l={top:n,height:e.railYHeight};e.isScrollbarYUsingRight?e.isRtl?l.right=e.contentWidth-(e.negativeScrollAdjustment+t.scrollLeft)-e.scrollbarYRight-e.scrollbarYOuterWidth-9:l.right=e.scrollbarYRight-t.scrollLeft:e.isRtl?l.left=e.negativeScrollAdjustment+t.scrollLeft+e.containerWidth*2-e.contentWidth-e.scrollbarYLeft-e.scrollbarYOuterWidth:l.left=e.scrollbarYLeft+t.scrollLeft,X(e.scrollbarYRail,l),X(e.scrollbarX,{left:e.scrollbarXLeft,width:e.scrollbarXWidth-e.railBorderXWidth}),X(e.scrollbarY,{top:e.scrollbarYTop,height:e.scrollbarYHeight-e.railBorderYWidth})}function Ue(t){t.event.bind(t.scrollbarY,"mousedown",function(e){return e.stopPropagation()}),t.event.bind(t.scrollbarYRail,"mousedown",function(e){var d=e.pageY-window.pageYOffset-t.scrollbarYRail.getBoundingClientRect().top,n=d>t.scrollbarYTop?1:-1;t.element.scrollTop+=n*t.containerHeight,B(t),e.stopPropagation()}),t.event.bind(t.scrollbarX,"mousedown",function(e){return e.stopPropagation()}),t.event.bind(t.scrollbarXRail,"mousedown",function(e){var d=e.pageX-window.pageXOffset-t.scrollbarXRail.getBoundingClientRect().left,n=d>t.scrollbarXLeft?1:-1;t.element.scrollLeft+=n*t.containerWidth,B(t),e.stopPropagation()})}var lt=null;function Fe(t){wt(t,["containerHeight","contentHeight","pageY","railYHeight","scrollbarY","scrollbarYHeight","scrollTop","y","scrollbarYRail"]),wt(t,["containerWidth","contentWidth","pageX","railXWidth","scrollbarX","scrollbarXWidth","scrollLeft","x","scrollbarXRail"])}function wt(t,e){var d=e[0],n=e[1],l=e[2],u=e[3],g=e[4],h=e[5],p=e[6],v=e[7],_=e[8],f=t.element,y=null,w=null,m=null;function r($){$.touches&&$.touches[0]&&($[l]=$.touches[0]["page"+v.toUpperCase()]),lt===g&&(f[p]=y+m*($[l]-w),Tt(t,v),B(t),$.stopPropagation(),$.preventDefault())}function c(){Yt(t,v),t[_].classList.remove(T.state.clicking),document.removeEventListener("mousemove",r),document.removeEventListener("mouseup",c),document.removeEventListener("touchmove",r),document.removeEventListener("touchend",c),lt=null}function b($){lt===null&&(lt=g,y=f[p],$.touches&&($[l]=$.touches[0]["page"+v.toUpperCase()]),w=$[l],m=(t[n]-t[d])/(t[u]-t[h]),$.touches?(document.addEventListener("touchmove",r,{passive:!1}),document.addEventListener("touchend",c)):(document.addEventListener("mousemove",r),document.addEventListener("mouseup",c)),t[_].classList.add(T.state.clicking)),$.stopPropagation(),$.cancelable&&$.preventDefault()}t[g].addEventListener("mousedown",b),t[g].addEventListener("touchstart",b)}function Ve(t){var e=t.element,d=function(){return z(e,":hover")},n=function(){return z(t.scrollbarX,":focus")||z(t.scrollbarY,":focus")};function l(u,g){var h=Math.floor(e.scrollTop);if(u===0){if(!t.scrollbarYActive)return!1;if(h===0&&g>0||h>=t.contentHeight-t.containerHeight&&g<0)return!t.settings.wheelPropagation}var p=e.scrollLeft;if(g===0){if(!t.scrollbarXActive)return!1;if(p===0&&u<0||p>=t.contentWidth-t.containerWidth&&u>0)return!t.settings.wheelPropagation}return!0}t.event.bind(t.ownerDocument,"keydown",function(u){if(!(u.isDefaultPrevented&&u.isDefaultPrevented()||u.defaultPrevented)&&!(!d()&&!n())){var g=document.activeElement?document.activeElement:t.ownerDocument.activeElement;if(g){if(g.tagName==="IFRAME")g=g.contentDocument.activeElement;else for(;g.shadowRoot;)g=g.shadowRoot.activeElement;if(ze(g))return}var h=0,p=0;switch(u.which){case 37:u.metaKey?h=-t.contentWidth:u.altKey?h=-t.containerWidth:h=-30;break;case 38:u.metaKey?p=t.contentHeight:u.altKey?p=t.containerHeight:p=30;break;case 39:u.metaKey?h=t.contentWidth:u.altKey?h=t.containerWidth:h=30;break;case 40:u.metaKey?p=-t.contentHeight:u.altKey?p=-t.containerHeight:p=-30;break;case 32:u.shiftKey?p=t.containerHeight:p=-t.containerHeight;break;case 33:p=t.containerHeight;break;case 34:p=-t.containerHeight;break;case 36:p=t.contentHeight;break;case 35:p=-t.contentHeight;break;default:return}t.settings.suppressScrollX&&h!==0||t.settings.suppressScrollY&&p!==0||(e.scrollTop-=p,e.scrollLeft+=h,B(t),l(h,p)&&u.preventDefault())}})}function Ge(t){var e=t.element;function d(g,h){var p=Math.floor(e.scrollTop),v=e.scrollTop===0,_=p+e.offsetHeight===e.scrollHeight,f=e.scrollLeft===0,y=e.scrollLeft+e.offsetWidth===e.scrollWidth,w;return Math.abs(h)>Math.abs(g)?w=v||_:w=f||y,w?!t.settings.wheelPropagation:!0}function n(g){var h=g.deltaX,p=-1*g.deltaY;return(typeof h>"u"||typeof p>"u")&&(h=-1*g.wheelDeltaX/6,p=g.wheelDeltaY/6),g.deltaMode&&g.deltaMode===1&&(h*=10,p*=10),h!==h&&p!==p&&(h=0,p=g.wheelDelta),g.shiftKey?[-p,-h]:[h,p]}function l(g,h,p){if(!K.isWebKit&&e.querySelector("select:focus"))return!0;if(!e.contains(g))return!1;for(var v=g;v&&v!==e;){if(v.classList.contains(T.element.consuming))return!0;var _=I(v);if(p&&_.overflowY.match(/(scroll|auto)/)){var f=v.scrollHeight-v.clientHeight;if(f>0&&(v.scrollTop>0&&p<0||v.scrollTop<f&&p>0))return!0}if(h&&_.overflowX.match(/(scroll|auto)/)){var y=v.scrollWidth-v.clientWidth;if(y>0&&(v.scrollLeft>0&&h<0||v.scrollLeft<y&&h>0))return!0}v=v.parentNode}return!1}function u(g){var h=n(g),p=h[0],v=h[1];if(!l(g.target,p,v)){var _=!1;t.settings.useBothWheelAxes?t.scrollbarYActive&&!t.scrollbarXActive?(v?e.scrollTop-=v*t.settings.wheelSpeed:e.scrollTop+=p*t.settings.wheelSpeed,_=!0):t.scrollbarXActive&&!t.scrollbarYActive&&(p?e.scrollLeft+=p*t.settings.wheelSpeed:e.scrollLeft-=v*t.settings.wheelSpeed,_=!0):(e.scrollTop-=v*t.settings.wheelSpeed,e.scrollLeft+=p*t.settings.wheelSpeed),B(t),_=_||d(p,v),_&&!g.ctrlKey&&(g.stopPropagation(),g.preventDefault())}}typeof window.onwheel<"u"?t.event.bind(e,"wheel",u):typeof window.onmousewheel<"u"&&t.event.bind(e,"mousewheel",u)}function Qe(t){if(!K.supportsTouch&&!K.supportsIePointer)return;var e=t.element,d={startOffset:{},startTime:0,speed:{},easingLoop:null};function n(f,y){var w=Math.floor(e.scrollTop),m=e.scrollLeft,r=Math.abs(f),c=Math.abs(y);if(c>r){if(y<0&&w===t.contentHeight-t.containerHeight||y>0&&w===0)return window.scrollY===0&&y>0&&K.isChrome}else if(r>c&&(f<0&&m===t.contentWidth-t.containerWidth||f>0&&m===0))return!0;return!0}function l(f,y){e.scrollTop-=y,e.scrollLeft-=f,B(t)}function u(f){return f.targetTouches?f.targetTouches[0]:f}function g(f){return f.target===t.scrollbarX||f.target===t.scrollbarY||f.pointerType&&f.pointerType==="pen"&&f.buttons===0?!1:!!(f.targetTouches&&f.targetTouches.length===1||f.pointerType&&f.pointerType!=="mouse"&&f.pointerType!==f.MSPOINTER_TYPE_MOUSE)}function h(f){if(g(f)){var y=u(f);d.startOffset.pageX=y.pageX,d.startOffset.pageY=y.pageY,d.startTime=new Date().getTime(),d.easingLoop!==null&&clearInterval(d.easingLoop)}}function p(f,y,w){if(!e.contains(f))return!1;for(var m=f;m&&m!==e;){if(m.classList.contains(T.element.consuming))return!0;var r=I(m);if(w&&r.overflowY.match(/(scroll|auto)/)){var c=m.scrollHeight-m.clientHeight;if(c>0&&(m.scrollTop>0&&w<0||m.scrollTop<c&&w>0))return!0}if(y&&r.overflowX.match(/(scroll|auto)/)){var b=m.scrollWidth-m.clientWidth;if(b>0&&(m.scrollLeft>0&&y<0||m.scrollLeft<b&&y>0))return!0}m=m.parentNode}return!1}function v(f){if(g(f)){var y=u(f),w={pageX:y.pageX,pageY:y.pageY},m=w.pageX-d.startOffset.pageX,r=w.pageY-d.startOffset.pageY;if(p(f.target,m,r))return;l(m,r),d.startOffset=w;var c=new Date().getTime(),b=c-d.startTime;b>0&&(d.speed.x=m/b,d.speed.y=r/b,d.startTime=c),n(m,r)&&f.cancelable&&f.preventDefault()}}function _(){t.settings.swipeEasing&&(clearInterval(d.easingLoop),d.easingLoop=setInterval(function(){if(t.isInitialized){clearInterval(d.easingLoop);return}if(!d.speed.x&&!d.speed.y){clearInterval(d.easingLoop);return}if(Math.abs(d.speed.x)<.01&&Math.abs(d.speed.y)<.01){clearInterval(d.easingLoop);return}l(d.speed.x*30,d.speed.y*30),d.speed.x*=.8,d.speed.y*=.8},10))}K.supportsTouch?(t.event.bind(e,"touchstart",h),t.event.bind(e,"touchmove",v),t.event.bind(e,"touchend",_)):K.supportsIePointer&&(window.PointerEvent?(t.event.bind(e,"pointerdown",h),t.event.bind(e,"pointermove",v),t.event.bind(e,"pointerup",_)):window.MSPointerEvent&&(t.event.bind(e,"MSPointerDown",h),t.event.bind(e,"MSPointerMove",v),t.event.bind(e,"MSPointerUp",_)))}var Je=function(){return{handlers:["click-rail","drag-thumb","keyboard","wheel","touch"],maxScrollbarLength:null,minScrollbarLength:null,scrollingThreshold:1e3,scrollXMarginOffset:0,scrollYMarginOffset:0,suppressScrollX:!1,suppressScrollY:!1,swipeEasing:!0,useBothWheelAxes:!1,wheelPropagation:!0,wheelSpeed:1}},Ze={"click-rail":Ue,"drag-thumb":Fe,keyboard:Ve,wheel:Ge,touch:Qe},tt=function(e,d){var n=this;if(d===void 0&&(d={}),typeof e=="string"&&(e=document.querySelector(e)),!e||!e.nodeName)throw new Error("no element is specified to initialize PerfectScrollbar");this.element=e,e.classList.add(T.main),this.settings=Je();for(var l in d)this.settings[l]=d[l];this.containerWidth=null,this.containerHeight=null,this.contentWidth=null,this.contentHeight=null;var u=function(){return e.classList.add(T.state.focus)},g=function(){return e.classList.remove(T.state.focus)};this.isRtl=I(e).direction==="rtl",this.isRtl===!0&&e.classList.add(T.rtl),this.isNegativeScroll=function(){var v=e.scrollLeft,_=null;return e.scrollLeft=-1,_=e.scrollLeft<0,e.scrollLeft=v,_}(),this.negativeScrollAdjustment=this.isNegativeScroll?e.scrollWidth-e.clientWidth:0,this.event=new Q,this.ownerDocument=e.ownerDocument||document,this.scrollbarXRail=it(T.element.rail("x")),e.appendChild(this.scrollbarXRail),this.scrollbarX=it(T.element.thumb("x")),this.scrollbarXRail.appendChild(this.scrollbarX),this.scrollbarX.setAttribute("tabindex",0),this.event.bind(this.scrollbarX,"focus",u),this.event.bind(this.scrollbarX,"blur",g),this.scrollbarXActive=null,this.scrollbarXWidth=null,this.scrollbarXLeft=null;var h=I(this.scrollbarXRail);this.scrollbarXBottom=parseInt(h.bottom,10),isNaN(this.scrollbarXBottom)?(this.isScrollbarXUsingBottom=!1,this.scrollbarXTop=j(h.top)):this.isScrollbarXUsingBottom=!0,this.railBorderXWidth=j(h.borderLeftWidth)+j(h.borderRightWidth),X(this.scrollbarXRail,{display:"block"}),this.railXMarginWidth=j(h.marginLeft)+j(h.marginRight),X(this.scrollbarXRail,{display:""}),this.railXWidth=null,this.railXRatio=null,this.scrollbarYRail=it(T.element.rail("y")),e.appendChild(this.scrollbarYRail),this.scrollbarY=it(T.element.thumb("y")),this.scrollbarYRail.appendChild(this.scrollbarY),this.scrollbarY.setAttribute("tabindex",0),this.event.bind(this.scrollbarY,"focus",u),this.event.bind(this.scrollbarY,"blur",g),this.scrollbarYActive=null,this.scrollbarYHeight=null,this.scrollbarYTop=null;var p=I(this.scrollbarYRail);this.scrollbarYRight=parseInt(p.right,10),isNaN(this.scrollbarYRight)?(this.isScrollbarYUsingRight=!1,this.scrollbarYLeft=j(p.left)):this.isScrollbarYUsingRight=!0,this.scrollbarYOuterWidth=this.isRtl?qe(this.scrollbarY):null,this.railBorderYWidth=j(p.borderTopWidth)+j(p.borderBottomWidth),X(this.scrollbarYRail,{display:"block"}),this.railYMarginHeight=j(p.marginTop)+j(p.marginBottom),X(this.scrollbarYRail,{display:""}),this.railYHeight=null,this.railYRatio=null,this.reach={x:e.scrollLeft<=0?"start":e.scrollLeft>=this.contentWidth-this.containerWidth?"end":null,y:e.scrollTop<=0?"start":e.scrollTop>=this.contentHeight-this.containerHeight?"end":null},this.isAlive=!0,this.settings.handlers.forEach(function(v){return Ze[v](n)}),this.lastScrollTop=Math.floor(e.scrollTop),this.lastScrollLeft=e.scrollLeft,this.event.bind(this.element,"scroll",function(v){return n.onScroll(v)}),B(this)};tt.prototype.update=function(){this.isAlive&&(this.negativeScrollAdjustment=this.isNegativeScroll?this.element.scrollWidth-this.element.clientWidth:0,X(this.scrollbarXRail,{display:"block"}),X(this.scrollbarYRail,{display:"block"}),this.railXMarginWidth=j(I(this.scrollbarXRail).marginLeft)+j(I(this.scrollbarXRail).marginRight),this.railYMarginHeight=j(I(this.scrollbarYRail).marginTop)+j(I(this.scrollbarYRail).marginBottom),X(this.scrollbarXRail,{display:"none"}),X(this.scrollbarYRail,{display:"none"}),B(this),rt(this,"top",0,!1,!0),rt(this,"left",0,!1,!0),X(this.scrollbarXRail,{display:""}),X(this.scrollbarYRail,{display:""}))};tt.prototype.onScroll=function(e){this.isAlive&&(B(this),rt(this,"top",this.element.scrollTop-this.lastScrollTop),rt(this,"left",this.element.scrollLeft-this.lastScrollLeft),this.lastScrollTop=Math.floor(this.element.scrollTop),this.lastScrollLeft=this.element.scrollLeft)};tt.prototype.destroy=function(){this.isAlive&&(this.event.unbindAll(),U(this.scrollbarX),U(this.scrollbarY),U(this.scrollbarXRail),U(this.scrollbarYRail),this.removePsClasses(),this.element=null,this.scrollbarX=null,this.scrollbarY=null,this.scrollbarXRail=null,this.scrollbarYRail=null,this.isAlive=!1)};tt.prototype.removePsClasses=function(){this.element.className=this.element.className.split(" ").filter(function(e){return!e.match(/^ps([-_].+|)$/)}).join(" ")};const Ct=["scroll","ps-scroll-y","ps-scroll-x","ps-scroll-up","ps-scroll-down","ps-scroll-left","ps-scroll-right","ps-y-reach-start","ps-y-reach-end","ps-x-reach-start","ps-x-reach-end"];var Rt={name:"PerfectScrollbar",props:{options:{type:Object,required:!1,default:()=>{}},tag:{type:String,required:!1,default:"div"},watchOptions:{type:Boolean,required:!1,default:!1}},emits:Ct,data(){return{ps:null}},watch:{watchOptions(t){!t&&this.watcher?this.watcher():this.createWatcher()}},mounted(){this.create(),this.watchOptions&&this.createWatcher()},updated(){this.$nextTick(()=>{this.update()})},beforeUnmount(){this.destroy()},methods:{create(){this.ps&&this.$isServer||(this.ps=new tt(this.$el,this.options),Ct.forEach(t=>{this.ps.element.addEventListener(t,e=>this.$emit(t,e))}))},createWatcher(){this.watcher=this.$watch("options",()=>{this.destroy(),this.create()},{deep:!0})},update(){this.ps&&this.ps.update()},destroy(){this.ps&&(this.ps.destroy(),this.ps=null)}},render(){return It(this.tag,{class:"ps"},this.$slots.default&&this.$slots.default())}};const tn={class:"ninjadash-nav-actions__item ninjadash-nav-actions__notification"},en={key:0,class:"ninjadash-top-dropdown__nav notification-list"},nn={class:"ninjadash-top-dropdown__content notifications"},on={class:"notification-content d-flex"},an={class:"notification-text"},ln={class:"notification-status"},sn={key:1,class:"notification-empty"},rn=q({__name:"Notification",setup(t){bt.extend(te);const e=R([]),d=R(0),n=R(!0),l={CRITICAL:{icon:"exclamation-triangle",class:"bg-danger"},WARNING:{icon:"bell",class:"bg-warning"},INFO:{icon:"info-circle",class:"bg-primary"}},u=N(()=>e.value.length>0),g=R(null),h=()=>{g.value&&(g.value.visible=!1)};async function p(){try{const[v,_]=await Promise.all([st.get("/component/events/severity-stat"),st.get("/component/events",{not_resolved:!0,limit:6})]),f=v.data.data;d.value=(f.WARNING||0)+(f.CRITICAL||0),e.value=(_.data.data||[]).slice(0,6)}catch(v){console.error("Failed to load event notifications",v)}finally{n.value=!1}}return Lt(p),(v,_)=>{const f=Bt,y=E("sdHeading"),w=E("unicon"),m=E("router-link"),r=E("sdPopover");return C(),M("div",tn,[o(r,{ref_key:"notifPopoverRef",ref:g,placement:"bottomLeft",action:"click"},{content:a(()=>[o(S(Ie),{class:"ninjadash-top-dropdown"},{default:a(()=>[o(y,{as:"h5",class:"ninjadash-top-dropdown__title"},{default:a(()=>[_[0]||(_[0]=i("span",{class:"title-text"},"Unresolved events",-1)),d.value?(C(),D(f,{key:0,class:"badge-danger",count:d.value,"overflow-count":99},null,8,["count"])):H("",!0)]),_:1}),o(S(Rt),{options:{wheelSpeed:1,swipeEasing:!0,suppressScrollX:!0}},{default:a(()=>[u.value?(C(),M("ul",en,[(C(!0),M(F,null,ut(e.value,c=>(C(),M("li",{key:c.id},[o(m,{to:{name:"events"},onClick:h},{default:a(()=>{var b,$,x;return[i("div",nn,[i("div",{class:ft(["notification-icon",((b=l[c.severity])==null?void 0:b.class)||"bg-primary"])},[o(w,{name:(($=l[c.severity])==null?void 0:$.icon)||"bell"},null,8,["name"])],2),i("div",on,[i("div",an,[o(y,{as:"h5"},{default:a(()=>[s(Y(c.annotation),1)]),_:2},1024),i("p",null,Y((x=c.device)!=null&&x.name?c.device.name+" · ":"")+Y(S(bt)(c.created_at).fromNow()),1)]),i("div",ln,[o(f,{dot:""})])])])]}),_:2},1024)]))),128))])):n.value?H("",!0):(C(),M("div",sn,"No unresolved events right now."))]),_:1}),o(m,{class:"btn-seeAll",to:{name:"events"},onClick:h},{default:a(()=>[..._[1]||(_[1]=[s(" See all events ",-1)])]),_:1})]),_:1})]),default:a(()=>[o(f,{dot:d.value>0,offset:[-8,-5]},{default:a(()=>[..._[2]||(_[2]=[i("a",{to:"#",class:"ninjadash-nav-action-link"},[i("img",{src:"/src/assets/img/icon/alarm.svg"})],-1)])]),_:1},8,["dot"])]),_:1},512)])}}});const dn=V(rn,[["__scopeId","data-v-b6b4f7fe"]]),un={class:"ninjadash-nav-actions__item ninjadash-nav-actions__author"},cn={class:"account-menu"},pn={class:"account-menu__header"},mn={class:"account-menu__avatar"},fn={class:"account-menu__identity"},gn={class:"account-menu__name"},hn={key:0,class:"account-menu__login"},vn={key:1,class:"account-menu__role"},bn={class:"account-menu__list"},xn={class:"account-menu__item-icon"},yn={class:"account-menu__item-icon"},kn={to:"#",class:"ninjadash-nav-action-link"},_n={class:"ninjadash-nav-actions__author-avatar"},wn={class:"ninjadash-nav-actions__author--name"},Cn=q({__name:"Info",setup(t){const{dispatch:e,state:d}=ht(),{push:n}=dt(),l=N(()=>d.auth.user),u=N(()=>{var y;return((y=l.value)==null?void 0:y.name)||"Unknown user"}),g=N(()=>{var y;return((y=l.value)==null?void 0:y.login)||""}),h=N(()=>{var y,w;return((w=(y=l.value)==null?void 0:y.role)==null?void 0:w.name)||""}),p=N(()=>{var y;return(((y=u.value)==null?void 0:y[0])||"?").toUpperCase()}),v=R(null),_=()=>{v.value&&(v.value.visible=!1)},f=async y=>{y.preventDefault(),_(),await e("logOut"),n("/auth/login")};return(y,w)=>{const m=E("unicon"),r=E("router-link"),c=E("sdPopover");return C(),D(S(Ne),null,{default:a(()=>[o(dn),i("div",un,[o(c,{ref_key:"accountMenuRef",ref:v,placement:"bottomRight",action:"click","overlay-class-name":"account-menu-popover"},{content:a(()=>[i("div",cn,[i("div",pn,[i("span",mn,Y(p.value),1),i("div",fn,[i("p",gn,Y(u.value),1),g.value?(C(),M("p",hn,"@"+Y(g.value),1)):H("",!0),h.value?(C(),M("span",vn,[o(m,{name:"shield"}),s(" "+Y(h.value),1)])):H("",!0)])]),w[2]||(w[2]=i("div",{class:"account-menu__divider"},null,-1)),i("ul",bn,[i("li",null,[o(r,{to:{name:"account-settings"},class:"account-menu__item",onClick:_},{default:a(()=>[i("span",xn,[o(m,{name:"setting"})]),w[0]||(w[0]=i("span",null,"Account settings",-1))]),_:1})])]),w[3]||(w[3]=i("div",{class:"account-menu__divider"},null,-1)),i("button",{type:"button",class:"account-menu__item account-menu__item--danger",onClick:f},[i("span",yn,[o(m,{name:"signout"})]),w[1]||(w[1]=i("span",null,"Sign out",-1))])])]),default:a(()=>[i("a",kn,[i("span",_n,Y(p.value),1),i("span",wn,Y(u.value),1),o(m,{name:"angle-down"})])]),_:1},512)])]),_:1})}}});const $t=V(Cn,[["__scopeId","data-v-7cf2d004"]]),$n=q({__name:"Aside",props:{toggleCollapsed:{type:Function,required:!0},events:{type:Object,required:!0}},setup(t){const e=t,d=ht(),n=N(()=>d.state.themeLayout.data),l=R("inline"),{events:u}=qt(e);u.value;const g=Ot(),h=Kt({selectedKeys:[],openKeys:[]}),p={"device-management":"device-mgmt","device-management-create":"device-mgmt","device-management-edit":"device-mgmt","device-access":"device-mgmt","device-group":"device-mgmt","device-model":"device-mgmt","device-model-edit":"device-mgmt",autodiscovery:"device-mgmt","ont-list":"interfaces","favorite-interfaces":"interfaces","tagged-interfaces":"interfaces","links-list":"links","topology-graph":"links","topology-tree":"links","analytics-increasing-errors":"analytics","analytics-ont-statuses":"analytics","analytics-duplicated-mac":"analytics","analytics-ont-level-strength":"analytics","analytics-duplicated-onts":"analytics","analytics-device-statuses":"analytics","logs-console":"logs","logs-actions":"logs","logs-device-calling":"logs","logs-traps":"logs","logs-poller":"logs","logs-schedule-reports":"logs",users:"user-mgmt","users-create":"user-mgmt","users-edit":"user-mgmt","user-roles":"user-mgmt","user-roles-create":"user-mgmt","user-roles-edit":"user-mgmt",macros:"config","onts-registration":"config","notifications-config":"config","events-config":"config","system-config":"config","qr-devices":"qr","qr-interfaces":"qr"};zt(()=>{const m=g.name;if(!m)return;h.selectedKeys=[m];const r=p[m];h.openKeys=r?[r]:[]});const v=m=>{h.openKeys=m.length?[m[m.length-1]]:[]},_=[{key:"oxidized",label:"Oxidized",href:"/oxidized/",icon:"save"},{key:"grafana",label:"Grafana",href:"/grafana/",icon:"chart-line"},{key:"prometheus",label:"Prometheus",href:"/prometheus/",icon:"fire"},{key:"alertmanager",label:"Alertmanager",href:"/alertmanager/",icon:"bell"},{key:"phpmyadmin",label:"phpMyAdmin",href:"/phpmyadmin/",icon:"database"}],f=Object.fromEntries(_.map(m=>[m.key,m.href])),y=dt(),w=({key:m})=>{if(e.toggleCollapsed(),m in f){window.open(f[m],"_blank","noopener");return}y.push({name:m})};return(m,r)=>{const c=E("unicon"),b=Ut,$=Ft,x=Vt;return C(),D(x,{"open-keys":h.openKeys,selectedKeys:h.selectedKeys,"onUpdate:selectedKeys":r[0]||(r[0]=k=>h.selectedKeys=k),mode:l.value,theme:n.value?"dark":"light",class:"scroll-menu",onOpenChange:v,onClick:w},{default:a(()=>[o(b,{key:"dashboard"},{icon:a(()=>[o(c,{name:"create-dashboard"})]),default:a(()=>[r[1]||(r[1]=s(" Dashboard ",-1))]),_:1}),o(b,{key:"devices-list"},{icon:a(()=>[o(c,{name:"server-network"})]),default:a(()=>[r[2]||(r[2]=s(" Devices ",-1))]),_:1}),o($,{key:"interfaces"},{icon:a(()=>[o(c,{name:"wifi"})]),title:a(()=>[...r[3]||(r[3]=[s("Interfaces",-1)])]),default:a(()=>[o(b,{key:"ont-list"},{icon:a(()=>[o(c,{name:"signal-alt-3"})]),default:a(()=>[r[4]||(r[4]=s(" ONT list ",-1))]),_:1}),o(b,{key:"favorite-interfaces"},{icon:a(()=>[o(c,{name:"star"})]),default:a(()=>[r[5]||(r[5]=s(" Favorite list ",-1))]),_:1}),o(b,{key:"tagged-interfaces"},{icon:a(()=>[o(c,{name:"tag-alt"})]),default:a(()=>[r[6]||(r[6]=s(" Tags ",-1))]),_:1})]),_:1}),o($,{key:"links"},{icon:a(()=>[o(c,{name:"share-alt"})]),title:a(()=>[...r[7]||(r[7]=[s("Links",-1)])]),default:a(()=>[o(b,{key:"links-list"},{icon:a(()=>[o(c,{name:"link-alt"})]),default:a(()=>[r[8]||(r[8]=s(" Links list ",-1))]),_:1}),o(b,{key:"topology-graph"},{icon:a(()=>[o(c,{name:"graph-bar"})]),default:a(()=>[r[9]||(r[9]=s(" Topology (graph view) ",-1))]),_:1}),o(b,{key:"topology-tree"},{icon:a(()=>[o(c,{name:"sitemap"})]),default:a(()=>[r[10]||(r[10]=s(" Topology (tree view) ",-1))]),_:1})]),_:1}),o(b,{key:"map"},{icon:a(()=>[o(c,{name:"map"})]),default:a(()=>[r[11]||(r[11]=s(" Map ",-1))]),_:1}),o(b,{key:"nearby"},{icon:a(()=>[o(c,{name:"location-point"})]),default:a(()=>[r[12]||(r[12]=s(" Nearby objects ",-1))]),_:1}),o(b,{key:"events"},{icon:a(()=>[o(c,{name:"bell"})]),default:a(()=>[r[13]||(r[13]=s(" Events ",-1))]),_:1}),o($,{key:"analytics"},{icon:a(()=>[o(c,{name:"chart-line"})]),title:a(()=>[...r[14]||(r[14]=[s("Analytics",-1)])]),default:a(()=>[o(b,{key:"analytics-increasing-errors"},{icon:a(()=>[o(c,{name:"arrow-growth"})]),default:a(()=>[r[15]||(r[15]=s(" Increasing errors ",-1))]),_:1}),o(b,{key:"analytics-ont-statuses"},{icon:a(()=>[o(c,{name:"signal-alt-3"})]),default:a(()=>[r[16]||(r[16]=s(" ONT statuses ",-1))]),_:1}),o(b,{key:"analytics-duplicated-mac"},{icon:a(()=>[o(c,{name:"copy"})]),default:a(()=>[r[17]||(r[17]=s(" Duplicated MACs ",-1))]),_:1}),o(b,{key:"analytics-ont-level-strength"},{icon:a(()=>[o(c,{name:"signal-alt"})]),default:a(()=>[r[18]||(r[18]=s(" Strength level ONTs ",-1))]),_:1}),o(b,{key:"analytics-duplicated-onts"},{icon:a(()=>[o(c,{name:"copy-alt"})]),default:a(()=>[r[19]||(r[19]=s(" Duplicated ONTs ",-1))]),_:1}),o(b,{key:"analytics-device-statuses"},{icon:a(()=>[o(c,{name:"server"})]),default:a(()=>[r[20]||(r[20]=s(" Device statuses ",-1))]),_:1})]),_:1}),o($,{key:"logs"},{icon:a(()=>[o(c,{name:"document-layout-left"})]),title:a(()=>[...r[21]||(r[21]=[s("Logs",-1)])]),default:a(()=>[o(b,{key:"logs-console"},{icon:a(()=>[o(c,{name:"window-section"})]),default:a(()=>[r[22]||(r[22]=s(" Console logs ",-1))]),_:1}),o(b,{key:"logs-actions"},{icon:a(()=>[o(c,{name:"history"})]),default:a(()=>[r[23]||(r[23]=s(" Actions ",-1))]),_:1}),o(b,{key:"logs-device-calling"},{icon:a(()=>[o(c,{name:"exchange"})]),default:a(()=>[r[24]||(r[24]=s(" Device calling logs ",-1))]),_:1}),o(b,{key:"logs-traps"},{icon:a(()=>[o(c,{name:"bell"})]),default:a(()=>[r[25]||(r[25]=s(" SNMP traps ",-1))]),_:1}),o(b,{key:"logs-poller"},{icon:a(()=>[o(c,{name:"sync"})]),default:a(()=>[r[26]||(r[26]=s(" Poller logs ",-1))]),_:1}),o(b,{key:"logs-schedule-reports"},{icon:a(()=>[o(c,{name:"calendar-alt"})]),default:a(()=>[r[27]||(r[27]=s(" Schedule reports ",-1))]),_:1})]),_:1}),o(S(ct),{class:"ninjadash-sidebar-nav-title"},{default:a(()=>[...r[28]||(r[28]=[s("Management",-1)])]),_:1}),o($,{key:"device-mgmt"},{icon:a(()=>[o(c,{name:"server"})]),title:a(()=>[...r[29]||(r[29]=[s("Device management",-1)])]),default:a(()=>[o(b,{key:"device-management"},{icon:a(()=>[o(c,{name:"edit"})]),default:a(()=>[r[30]||(r[30]=s(" Device management ",-1))]),_:1}),o(b,{key:"device-access"},{icon:a(()=>[o(c,{name:"lock"})]),default:a(()=>[r[31]||(r[31]=s(" Accesses ",-1))]),_:1}),o(b,{key:"device-group"},{icon:a(()=>[o(c,{name:"layer-group"})]),default:a(()=>[r[32]||(r[32]=s(" Groups ",-1))]),_:1}),o(b,{key:"device-model"},{icon:a(()=>[o(c,{name:"box"})]),default:a(()=>[r[33]||(r[33]=s(" Models ",-1))]),_:1}),o(b,{key:"autodiscovery"},{icon:a(()=>[o(c,{name:"search"})]),default:a(()=>[r[34]||(r[34]=s(" Autodiscovery ",-1))]),_:1})]),_:1}),o($,{key:"user-mgmt"},{icon:a(()=>[o(c,{name:"users-alt"})]),title:a(()=>[...r[35]||(r[35]=[s("Users",-1)])]),default:a(()=>[o(b,{key:"users"},{icon:a(()=>[o(c,{name:"user"})]),default:a(()=>[r[36]||(r[36]=s(" Users ",-1))]),_:1}),o(b,{key:"user-roles"},{icon:a(()=>[o(c,{name:"shield-check"})]),default:a(()=>[r[37]||(r[37]=s(" Roles ",-1))]),_:1})]),_:1}),o($,{key:"config"},{icon:a(()=>[o(c,{name:"setting"})]),title:a(()=>[...r[38]||(r[38]=[s("Configuration",-1)])]),default:a(()=>[o(b,{key:"macros"},{icon:a(()=>[o(c,{name:"brackets-curly"})]),default:a(()=>[r[39]||(r[39]=s(" Macros ",-1))]),_:1}),o(b,{key:"onts-registration"},{icon:a(()=>[o(c,{name:"clipboard-notes"})]),default:a(()=>[r[40]||(r[40]=s(" ONTs registration ",-1))]),_:1}),o(b,{key:"notifications-config"},{icon:a(()=>[o(c,{name:"bell"})]),default:a(()=>[r[41]||(r[41]=s(" Notifications ",-1))]),_:1}),o(b,{key:"events-config"},{icon:a(()=>[o(c,{name:"exclamation-triangle"})]),default:a(()=>[r[42]||(r[42]=s(" Event configuration ",-1))]),_:1}),o(b,{key:"system-config"},{icon:a(()=>[o(c,{name:"setting"})]),default:a(()=>[r[43]||(r[43]=s(" System configuration ",-1))]),_:1})]),_:1}),o(S(ct),{class:"ninjadash-sidebar-nav-title"},{default:a(()=>[...r[44]||(r[44]=[s("External apps",-1)])]),_:1}),(C(),M(F,null,ut(_,k=>o(b,{key:k.key},{icon:a(()=>[o(c,{name:k.icon},null,8,["name"])]),default:a(()=>[s(" "+Y(k.label),1)]),_:2},1024)),64)),o(S(ct),{class:"ninjadash-sidebar-nav-title"},{default:a(()=>[...r[45]||(r[45]=[s("QR printing",-1)])]),_:1}),o($,{key:"qr"},{icon:a(()=>[o(c,{name:"qrcode-scan"})]),title:a(()=>[...r[46]||(r[46]=[s("QR printing",-1)])]),default:a(()=>[o(b,{key:"qr-devices"},{icon:a(()=>[o(c,{name:"server"})]),default:a(()=>[r[47]||(r[47]=s(" Devices ",-1))]),_:1}),o(b,{key:"qr-interfaces"},{icon:a(()=>[o(c,{name:"wifi"})]),default:a(()=>[r[48]||(r[48]=s(" Interfaces ",-1))]),_:1})]),_:1})]),_:1},8,["open-keys","selectedKeys","mode","theme"])}}});const Mn={class:"ninjadash-top-menu"},Ln={class:"has-subMenu"},Sn={class:"subMenu"},jn={class:"has-subMenu"},Tn={class:"subMenu"},Yn={class:"has-subMenu-left"},An={href:"#",class:"parent"},Rn={class:"subMenu"},En={class:"has-subMenu"},Wn={class:"subMenu"},Xn={class:"has-subMenu-left"},Dn={href:"#",class:"parent"},Pn={class:"subMenu"},Hn={class:"has-subMenu-left"},Nn={href:"#",class:"parent"},In={class:"subMenu"},Bn={class:"has-subMenu-left"},On={href:"#",class:"parent"},zn={class:"subMenu"},qn={class:"has-subMenu-left"},Kn={href:"#",class:"parent"},Un={class:"subMenu"},Fn={class:"mega-item has-subMenu"},Vn={class:"megaMenu-wrapper megaMenu-small"},Gn={class:"mega-item has-subMenu"},Qn={class:"megaMenu-wrapper megaMenu-wide"},Jn={class:"has-subMenu"},Zn={class:"subMenu"},to={class:"has-subMenu-left"},eo={href:"#",class:"parent"},no={class:"subMenu"},oo={class:"has-subMenu-left"},io={class:"subMenu"},ao={class:"has-subMenu-left"},lo={href:"#",class:"parent"},so={class:"subMenu"},ro={class:"has-subMenu-left"},uo={href:"#",class:"parent"},co={class:"subMenu"},po={class:"has-subMenu-left"},mo={href:"#",class:"parent"},fo={class:"subMenu"},go={class:"has-subMenu-left"},ho={href:"#",class:"parent"},vo={class:"subMenu"},bo={class:"has-subMenu-left"},xo={href:"#",class:"parent"},yo={class:"subMenu"},ko={class:"has-subMenu-left"},_o={href:"#",class:"parent"},wo={class:"subMenu"},Co=q({__name:"TopMenuItems",setup(t){Lt(()=>{const d=document.querySelector(".ninjadash-top-menu a.active"),n=()=>{const l=d.closest(".megaMenu-wrapper"),u=d.closest(".has-subMenu-left");l?d.closest(".megaMenu-wrapper").previousSibling.classList.add("active"):(d.closest("ul").previousSibling.classList.add("active"),u&&u.closest("ul").previousSibling.classList.add("active"))};window.addEventListener("load",d&&n),ee()});const e=d=>{document.querySelectorAll(".parent").forEach(u=>{u.classList.remove("active")});const n=d.currentTarget.closest(".has-subMenu-left");d.currentTarget.closest(".megaMenu-wrapper")?d.currentTarget.closest(".megaMenu-wrapper").previousSibling.classList.add("active"):(d.currentTarget.closest("ul").previousSibling.classList.add("active"),n&&n.closest("ul").previousSibling.classList.add("active"))};return(d,n)=>{const l=E("router-link"),u=E("unicon");return C(),D(S(ae),null,{default:a(()=>[i("div",Mn,[i("ul",null,[i("li",Ln,[n[5]||(n[5]=i("a",{href:"#",class:"parent"}," Dashboard ",-1)),i("ul",Sn,[i("li",{onClick:e},[o(l,{to:"/demo-one"},{default:a(()=>[...n[0]||(n[0]=[s("Demo 1",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/demo-two"},{default:a(()=>[...n[1]||(n[1]=[s("Demo 2",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/demo-three"},{default:a(()=>[...n[2]||(n[2]=[s("Demo 3",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/demo-four"},{default:a(()=>[...n[3]||(n[3]=[s("Demo 4",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/demo-five"},{default:a(()=>[...n[4]||(n[4]=[s("Demo 5",-1)])]),_:1})])])]),i("li",jn,[n[9]||(n[9]=i("a",{href:"#",class:"parent"}," Crud ",-1)),i("ul",Tn,[i("li",Yn,[i("a",An,[o(u,{name:"database"}),n[6]||(n[6]=s(" Axios Crud ",-1))]),i("ul",Rn,[i("li",{onClick:e},[o(l,{to:"/crud/axios-view"},{default:a(()=>[...n[7]||(n[7]=[s(" View All ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/crud/axios-add"},{default:a(()=>[...n[8]||(n[8]=[s(" Add New ",-1)])]),_:1})])])])])]),i("li",En,[n[32]||(n[32]=i("a",{href:"#",class:"parent"}," Apps ",-1)),i("ul",Wn,[i("li",Xn,[i("a",Dn,[o(u,{name:"envelope"}),n[10]||(n[10]=s(" Email ",-1))]),i("ul",Pn,[i("li",{onClick:e},[o(l,{to:"/app/mail/inbox"},{default:a(()=>[...n[11]||(n[11]=[s(" Inbox ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/app/mail-single/1585118055048"},{default:a(()=>[...n[12]||(n[12]=[s(" Read Email ",-1)])]),_:1})])])]),i("li",{onClick:e},[o(l,{to:"/app/chat/private/rofiq@gmail.com"},{default:a(()=>[o(u,{name:"comment-alt"}),n[13]||(n[13]=s(" Chat ",-1))]),_:1})]),i("li",Hn,[i("a",Nn,[o(u,{name:"shopping-cart"}),n[14]||(n[14]=s(" eComerce ",-1))]),i("ul",In,[i("li",{onClick:e},[o(l,{to:"/app/ecommerce/product/grid"},{default:a(()=>[...n[15]||(n[15]=[s(" Products ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/app/ecommerce/productDetails/1"},{default:a(()=>[...n[16]||(n[16]=[s(" Products Details ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/app/ecommerce/add-product"},{default:a(()=>[...n[17]||(n[17]=[s(" Product Add ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/app/ecommerce/edit-product"},{default:a(()=>[...n[18]||(n[18]=[s(" Product Edit ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/app/ecommerce/cart"},{default:a(()=>[...n[19]||(n[19]=[s(" Cart ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/app/ecommerce/orders"},{default:a(()=>[...n[20]||(n[20]=[s(" Orders ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/app/ecommerce/sellers"},{default:a(()=>[...n[21]||(n[21]=[s(" Sellers ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/app/ecommerce/Invoice"},{default:a(()=>[...n[22]||(n[22]=[s(" Invoices ",-1)])]),_:1})])])]),i("li",Bn,[i("a",On,[o(u,{name:"shutter-alt"}),n[23]||(n[23]=s(" Social App ",-1))]),i("ul",zn,[i("li",{onClick:e},[o(l,{to:"/app/social/profile/overview"},{default:a(()=>[...n[24]||(n[24]=[s(" My Profile ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/app/social/profile/timeline"},{default:a(()=>[...n[25]||(n[25]=[s(" Timeline ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/app/social/profile/activity"},{default:a(()=>[...n[26]||(n[26]=[s(" Activity ",-1)])]),_:1})])])]),i("li",qn,[i("a",Kn,[o(u,{name:"bullseye"}),n[27]||(n[27]=s(" Project ",-1))]),i("ul",Un,[i("li",{onClick:e},[o(l,{to:"/app/project/grid"},{default:a(()=>[...n[28]||(n[28]=[s(" Project Grid ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/app/project/list"},{default:a(()=>[...n[29]||(n[29]=[s(" Project List ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/app/project/create"},{default:a(()=>[...n[30]||(n[30]=[s(" Create Project ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/app/project/projectDetails/1"},{default:a(()=>[...n[31]||(n[31]=[s(" Project Details ",-1)])]),_:1})])])])])]),i("li",Fn,[n[46]||(n[46]=i("a",{href:"#",class:"parent"}," Pages ",-1)),i("ul",Vn,[i("li",null,[i("ul",null,[i("li",{onClick:e},[o(l,{to:"/page/profile-settings"},{default:a(()=>[...n[33]||(n[33]=[s(" Settings ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/page/gallery"},{default:a(()=>[...n[34]||(n[34]=[s(" Gallery ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/page/pricing"},{default:a(()=>[...n[35]||(n[35]=[s(" Pricing ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/page/banners"},{default:a(()=>[...n[36]||(n[36]=[s(" Banners ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/page/testimonials"},{default:a(()=>[...n[37]||(n[37]=[s(" Testimonials ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/page/faqs"},{default:a(()=>[...n[38]||(n[38]=[s(" Faq`s ",-1)])]),_:1})])])]),i("li",null,[i("ul",null,[i("li",{onClick:e},[o(l,{to:"/page/search"},{default:a(()=>[...n[39]||(n[39]=[s(" Search Results ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/page/starter"},{default:a(()=>[...n[40]||(n[40]=[s(" Blank Page ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/page/maintenance"},{default:a(()=>[...n[41]||(n[41]=[s(" Maintenance ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/page/404"},{default:a(()=>[...n[42]||(n[42]=[s(" 404 ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/page/comingSoon"},{default:a(()=>[...n[43]||(n[43]=[s(" Coming Soon ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/page/support"},{default:a(()=>[...n[44]||(n[44]=[s(" Support Center ",-1)])]),_:1})])])]),i("li",null,[i("ul",null,[i("li",{onClick:e},[o(l,{to:"/changelog"},{default:a(()=>[...n[45]||(n[45]=[s(" Changelog ",-1)])]),_:1})])])])])]),i("li",Gn,[n[97]||(n[97]=i("a",{href:"#",class:"parent"}," Components ",-1)),i("ul",Qn,[i("li",null,[n[58]||(n[58]=i("span",{class:"mega-title"},"Components",-1)),i("ul",null,[i("li",{onClick:e},[o(l,{to:"/components/alerts"},{default:a(()=>[...n[47]||(n[47]=[s(" Alert ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/avatar"},{default:a(()=>[...n[48]||(n[48]=[s(" Avatar ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/badge"},{default:a(()=>[...n[49]||(n[49]=[s(" Badge ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/breadcrumb"},{default:a(()=>[...n[50]||(n[50]=[s(" Breadcrumb ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/button"},{default:a(()=>[...n[51]||(n[51]=[s(" Buttons ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/calendar"},{default:a(()=>[...n[52]||(n[52]=[s(" Calendar ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/cards"},{default:a(()=>[...n[53]||(n[53]=[s(" Card ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/carousel"},{default:a(()=>[...n[54]||(n[54]=[s(" Carousel ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/cascader"},{default:a(()=>[...n[55]||(n[55]=[s(" Cascader ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/checkbox"},{default:a(()=>[...n[56]||(n[56]=[s(" Checkbox ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/collapse"},{default:a(()=>[...n[57]||(n[57]=[s(" Collapse ",-1)])]),_:1})])])]),i("li",null,[n[70]||(n[70]=i("span",{class:"mega-title"},"Components",-1)),i("ul",null,[i("li",{onClick:e},[o(l,{to:"/components/comments"},{default:a(()=>[...n[59]||(n[59]=[s(" Comments ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/dash-base"},{default:a(()=>[...n[60]||(n[60]=[s(" Dashboard Base ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/datePicker"},{default:a(()=>[...n[61]||(n[61]=[s(" DataPicker ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/drag-drop"},{default:a(()=>[...n[62]||(n[62]=[s(" Drag & Drop ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/drawer"},{default:a(()=>[...n[63]||(n[63]=[s(" Drawer ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/dropdown"},{default:a(()=>[...n[64]||(n[64]=[s(" Dropdown ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/empty"},{default:a(()=>[...n[65]||(n[65]=[s(" Empty ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/grid"},{default:a(()=>[...n[66]||(n[66]=[s(" Grid ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/input"},{default:a(()=>[...n[67]||(n[67]=[s(" Input ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/list"},{default:a(()=>[...n[68]||(n[68]=[s(" List ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/menu"},{default:a(()=>[...n[69]||(n[69]=[s(" Menu ",-1)])]),_:1})])])]),i("li",null,[n[83]||(n[83]=i("span",{class:"mega-title"},"Components",-1)),i("ul",null,[i("li",{onClick:e},[o(l,{to:"/components/message"},{default:a(()=>[...n[71]||(n[71]=[s(" Message ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/modal"},{default:a(()=>[...n[72]||(n[72]=[s(" Modals ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/notification"},{default:a(()=>[...n[73]||(n[73]=[s(" Notifications ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/pageHeader"},{default:a(()=>[...n[74]||(n[74]=[s(" Page Headers ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/pagination"},{default:a(()=>[...n[75]||(n[75]=[s(" Pagination ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/popConfirm"},{default:a(()=>[...n[76]||(n[76]=[s(" PopConfirm ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/popover"},{default:a(()=>[...n[77]||(n[77]=[s(" PopOver ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/progressbar"},{default:a(()=>[...n[78]||(n[78]=[s(" Progress ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/radio"},{default:a(()=>[...n[79]||(n[79]=[s(" Radio ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/rate"},{default:a(()=>[...n[80]||(n[80]=[s(" Rate ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/result"},{default:a(()=>[...n[81]||(n[81]=[s(" Result ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/select"},{default:a(()=>[...n[82]||(n[82]=[s(" Select ",-1)])]),_:1})])])]),i("li",null,[n[96]||(n[96]=i("span",{class:"mega-title"},"Components",-1)),i("ul",null,[i("li",{onClick:e},[o(l,{to:"/components/skeleton"},{default:a(()=>[...n[84]||(n[84]=[s(" Skeleton ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/slider"},{default:a(()=>[...n[85]||(n[85]=[s(" Slider ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/spiner"},{default:a(()=>[...n[86]||(n[86]=[s(" Spiner ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/statistic"},{default:a(()=>[...n[87]||(n[87]=[s(" Statistics ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/steps"},{default:a(()=>[...n[88]||(n[88]=[s(" Steps ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/switch"},{default:a(()=>[...n[89]||(n[89]=[s(" Switch ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/tabs"},{default:a(()=>[...n[90]||(n[90]=[s(" Tabs ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/tags"},{default:a(()=>[...n[91]||(n[91]=[s(" Tags ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/timeline"},{default:a(()=>[...n[92]||(n[92]=[s(" Timeline ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/time-picker"},{default:a(()=>[...n[93]||(n[93]=[s(" TimePicker ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/tree-select"},{default:a(()=>[...n[94]||(n[94]=[s(" Tree Select ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/components/upload"},{default:a(()=>[...n[95]||(n[95]=[s(" Upload ",-1)])]),_:1})])])])])]),i("li",Jn,[n[129]||(n[129]=i("a",{href:"#",class:"parent"}," Features ",-1)),i("ul",Zn,[i("li",to,[i("a",eo,[o(u,{name:"chart-bar"}),n[98]||(n[98]=s(" Charts ",-1))]),i("ul",no,[i("li",{onClick:e},[o(l,{to:"/chart/chart-js"},{default:a(()=>[...n[99]||(n[99]=[s(" Chart Js ",-1)])]),_:1})]),i("li",oo,[n[107]||(n[107]=i("a",{href:"#"},"Apex Charts",-1)),i("ul",io,[i("li",{onClick:e},[o(l,{to:"/chart/column-chart"},{default:a(()=>[...n[100]||(n[100]=[s(" Column Charts ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/chart/line-chart"},{default:a(()=>[...n[101]||(n[101]=[s(" Line Charts ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/chart/area-chart"},{default:a(()=>[...n[102]||(n[102]=[s(" Area Charts ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/chart/bar-chart"},{default:a(()=>[...n[103]||(n[103]=[s(" Bar Charts ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/chart/pie-chart"},{default:a(()=>[...n[104]||(n[104]=[s(" Pie Charts ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/chart/radar-charts"},{default:a(()=>[...n[105]||(n[105]=[s(" Radar Charts ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/chart/radialbar-chart"},{default:a(()=>[...n[106]||(n[106]=[s(" Radialbar Charts ",-1)])]),_:1})])])])])]),i("li",ao,[i("a",lo,[o(u,{name:"compact-disc"}),n[108]||(n[108]=s(" Form ",-1))]),i("ul",so,[i("li",{onClick:e},[o(l,{to:"/forms/form-layout"},{default:a(()=>[...n[109]||(n[109]=[s(" Form Layouts ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/forms/form-elements"},{default:a(()=>[...n[110]||(n[110]=[s(" Form Elements ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/forms/form-components"},{default:a(()=>[...n[111]||(n[111]=[s(" Form Components ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/forms/form-validation"},{default:a(()=>[...n[112]||(n[112]=[s(" Form Validation ",-1)])]),_:1})])])]),i("li",ro,[i("a",uo,[o(u,{name:"processor"}),n[113]||(n[113]=s(" Tables ",-1))]),i("ul",co,[i("li",{onClick:e},[o(l,{to:"/tables/basic"},{default:a(()=>[...n[114]||(n[114]=[s(" Basic Table ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/tables/dataTable"},{default:a(()=>[...n[115]||(n[115]=[s(" Data Table ",-1)])]),_:1})])])]),i("li",po,[i("a",mo,[o(u,{name:"server"}),n[116]||(n[116]=s(" Widgets ",-1))]),i("ul",fo,[i("li",{onClick:e},[o(l,{to:"/widgets/chart"},{default:a(()=>[...n[117]||(n[117]=[s(" Chart ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/widgets/card"},{default:a(()=>[...n[118]||(n[118]=[s(" Card ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/widgets/mixed"},{default:a(()=>[...n[119]||(n[119]=[s(" Mixed ",-1)])]),_:1})])])]),i("li",go,[i("a",ho,[o(u,{name:"server"}),n[120]||(n[120]=s(" Wizards ",-1))]),i("ul",vo,[i("li",{onClick:e},[o(l,{to:"/wizard/wizard1"},{default:a(()=>[...n[121]||(n[121]=[s(" Wizard 1 ",-1)])]),_:1})])])]),i("li",bo,[i("a",xo,[o(u,{name:"grid"}),n[122]||(n[122]=s(" Icons ",-1))]),i("ul",yo,[i("li",{onClick:e},[o(l,{to:"/icons/featherIcons"},{default:a(()=>[...n[123]||(n[123]=[s(" Feather Icons(svg) ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/icons/fa"},{default:a(()=>[...n[124]||(n[124]=[s(" Font Awesome ",-1)])]),_:1})])])]),i("li",ko,[i("a",_o,[o(u,{name:"map"}),n[125]||(n[125]=s(" Maps ",-1))]),i("ul",wo,[i("li",{onClick:e},[o(l,{to:"/maps/google"},{default:a(()=>[...n[126]||(n[126]=[s(" Google Maps ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/maps/leaflet"},{default:a(()=>[...n[127]||(n[127]=[s(" Leaflet Maps ",-1)])]),_:1})]),i("li",{onClick:e},[o(l,{to:"/maps/Vector"},{default:a(()=>[...n[128]||(n[128]=[s(" Vector Maps ",-1)])]),_:1})])])])])])])])]),_:1})}}}),$o={class:"ninjadash-header-content d-flex"},Mo={class:"ninjadash-header-content__left"},Lo={class:"navbar-brand align-cener-v"},So={key:0,src:Zt,alt:"CyberSathy",style:{height:"55px",width:"auto"}},jo={key:1,src:ne,alt:"CyberSathy",style:{height:"40px",width:"auto"}},To={class:"ninjadash-header-launchers d-flex align-center-v"},Yo={class:"ninjadash-header-content__right d-flex"},Ao={class:"ninjadash-navbar-menu d-flex align-center-v"},Ro={class:"ninjadash-nav-actions"},Eo={class:"top-right-wrap d-flex"},Wo={class:"spin"},Xo=q({__name:"AdminLayout",setup(t){const{Header:e,Footer:d,Sider:n,Content:l}=ot,u=R(!1),{dispatch:g,state:h}=ht(),p=N(()=>h.themeLayout.rtlData),v=N(()=>h.themeLayout.data),_=N(()=>h.themeLayout.topMenu),f=window.innerWidth;u.value=window.innerWidth<=1200&&!0;const y=L=>{L.preventDefault(),u.value=!u.value},w=()=>{f<=990&&(u.value=!u.value)};f<=990&&document.body.addEventListener("click",L=>{!L.target.closest(".ant-layout-sider")&&!L.target.closest(".navbar-brand .ant-btn")&&(u.value=!0)});const k={onRtlChange:()=>{document.querySelector("html").setAttribute("dir","rtl"),g("changeRtlMode",!0)},onLtrChange:()=>{document.querySelector("html").setAttribute("dir","ltr"),g("changeRtlMode",!1)},modeChangeDark:()=>{g("changeLayoutMode",!0)},modeChangeLight:()=>{g("changeLayoutMode",!1)},modeChangeTopNav:()=>{g("changeMenuMode",!0)},modeChangeSideNav:()=>{g("changeMenuMode",!1)}};return(L,P)=>{const A=E("router-link"),O=E("sdButton"),J=E("router-view"),et=gt,nt=Qt,Et=Jt;return C(),D(S(oe),{darkMode:v.value},{default:a(()=>[o(S(ot),{class:"layout"},{default:a(()=>[o(S(e),{style:xt({position:"fixed",width:"100%",top:0,[p.value?"right":"left"]:0})},{default:a(()=>[i("div",$o,[i("div",Mo,[i("div",Lo,[o(A,{class:ft(_.value&&S(f)>991?"ninjadash-logo top-menu":"ninjadash-logo"),to:"/"},{default:a(()=>[u.value?(C(),M("img",jo)):(C(),M("img",So))]),_:1},8,["class"]),!_.value||S(f)<=991?(C(),D(O,{key:0,onClick:y,type:"white"},{default:a(()=>[...P[0]||(P[0]=[i("img",{src:"/src/assets/img/icon/align-center-alt.svg",alt:"menu"},null,-1)])]),_:1})):H("",!0)])]),i("div",To,[o(we),o(Pe)]),i("div",Yo,[i("div",Ao,[_.value&&S(f)>991?(C(),D(Co,{key:0})):H("",!0)]),i("div",Ro,[_.value&&S(f)>991?(C(),D(S(ie),{key:0},{default:a(()=>[i("div",Eo,[o($t)])]),_:1})):(C(),D($t,{key:1}))])])])]),_:1},8,["style"]),o(S(ot),null,{default:a(()=>[!_.value||S(f)<=991?(C(),D(S(n),{key:0,width:280,style:xt({margin:"72px 0 0 0",padding:`${p.value?"20px 0px 55px 20px":"20px 20px 55px 0px"}`,overflowY:"auto",height:"100vh",position:"fixed",[p.value?"right":"left"]:0,zIndex:998}),collapsed:u.value,theme:v.value?"dark":"light"},{default:a(()=>[o(S(Rt),{options:{wheelSpeed:1,swipeEasing:!0,suppressScrollX:!0}},{default:a(()=>[o($n,{toggleCollapsed:w,topMenu:_.value,rtl:p.value,darkMode:v.value,events:k},null,8,["topMenu","rtl","darkMode"])]),_:1})]),_:1},8,["style","collapsed","theme"])):H("",!0),o(S(ot),{class:"ninjadash-main-layout"},{default:a(()=>[o(S(l),null,{default:a(()=>[(C(),D(Gt,null,{default:a(()=>[o(J)]),fallback:a(()=>[i("div",Wo,[o(et)])]),_:1})),o(S(d),{class:"admin-footer",style:{padding:"20px 30px 18px",color:"rgba(0, 0, 0, 0.65)",fontSize:"14px",background:"rgba(255, 255, 255, .90)",width:"100%",boxShadow:"0 -5px 10px rgba(146,153,184, 0.05)"}},{default:a(()=>[o(Et,null,{default:a(()=>[o(nt,{span:24},{default:a(()=>[...P[1]||(P[1]=[i("span",{class:"admin-footer__copyright"},"© 2026 CyberSathy IT and Technology Pvt LTD",-1)])]),_:1})]),_:1})]),_:1})]),_:1})]),_:1})]),_:1})]),_:1})]),_:1},8,["darkMode"])}}});const Oo=V(Xo,[["__scopeId","data-v-3b47802c"]]);export{Oo as default};
