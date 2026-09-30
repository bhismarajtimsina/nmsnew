import{u as j,r as c,w as f,g as v,h as g,j as e,t as o,k as d,v as w,q as b,C as V,c as Q,o as S,e as P,n as U,s as x,i as D,a6 as B,x as E,a8 as q,d as I}from"./index-DULVLRUP.js";const N={class:"row"},C={class:"col-12"},R={class:"row"},T={class:"col-4"},A={class:"mb-0"},F={class:"switch",title:"Enabled",style:{}},L={class:"col-4"},O={class:"mb-0"},W={class:"switch",title:"Enabled",style:{}},G={class:"col-4"},H={class:"mb-0"},be={__name:"QrParams",emits:["updated"],setup(u,{emit:h}){const{t:p}=j(),m=h,n=c({size:400,with_logo:!0,with_label:!1});return f(n,i=>{m("updated",i)},{deep:!0}),(i,t)=>(v(),g("div",N,[e("div",C,[e("form",R,[e("div",T,[e("label",A,o(i.$t("with_label")),1),t[4]||(t[4]=e("br",null,null,-1)),e("label",F,[d(e("input",{type:"checkbox","onUpdate:modelValue":t[0]||(t[0]=r=>n.value.with_label=r)},null,512),[[w,n.value.with_label]]),t[3]||(t[3]=e("span",{class:"slider"},null,-1))])]),e("div",L,[e("label",O,o(i.$t("with_logo")),1),t[6]||(t[6]=e("br",null,null,-1)),e("label",W,[d(e("input",{type:"checkbox","onUpdate:modelValue":t[1]||(t[1]=r=>n.value.with_logo=r)},null,512),[[w,n.value.with_logo]]),t[5]||(t[5]=e("span",{class:"slider"},null,-1))])]),e("div",G,[e("label",H,o(i.$t("size"))+" (px)",1),t[7]||(t[7]=e("br",null,null,-1)),d(e("input",{class:"form-control","onUpdate:modelValue":t[2]||(t[2]=r=>n.value.size=r),step:"10",style:{},type:"number"},null,512),[[b,n.value.size]])])])])]))}},J={style:{width:"100%",height:"100%","margin-bottom":"-8px"}},K={class:"row"},X={class:"col-12"},Y={key:0,style:{"text-align":"center",margin:"20px"}},Z={key:1,style:{"text-align":"center","font-weight":"bold","margin-bottom":"30px"}},ee={style:{"margin-bottom":"10px"}},te={class:"progress",style:{height:"20px",margin:"0 auto",width:"60%"}},le=["aria-valuenow"],se={class:"row",style:{padding:"10px"}},ae={class:"col-12"},oe={class:"col-3"},ne={class:"col-3"},ie={class:"col-3"},re={class:"col-3"},de={class:"switch-small",title:"Enabled",style:{}},ue={class:"row"},me={class:"col-12"},pe={style:{"margin-left":"10px","margin-right":"10px","font-weight":"bold"}},ce={class:"col-12"},ve=Object.assign({name:"QrPrintPage"},{__name:"QrMassIframe",props:{params:{type:Object,required:!0},objects:{type:Array,required:!0}},setup(u,{expose:h}){const p=u,m=c([]),n=c(0),i=c(!0),t=c({qrSizeMm:25,gapMm:.5,pageMarginMm:5,cutMarkLenMm:5,cutMarkThickMm:.2,displayDescription:!0}),r=c(null),y=Q(()=>p.objects.length?Math.round(n.value/p.objects.length*100):0),$=()=>{if(!r.value)return;const s=r.value.contentDocument||r.value.contentWindow.document,l=`<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>QR Print</title>
<style>
  @page { size: auto; margin: ${t.value.pageMarginMm}mm; }
  html, body { height: 100%; }
  body { margin:0; font-family: sans-serif; }
  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(${t.value.qrSizeMm}mm, 1fr));
    gap: ${t.value.gapMm}mm;
    padding: ${t.value.pageMarginMm}mm;
    box-sizing: border-box;
  }
  .cell {
    display:flex; justify-content:center;
    position: relative;
  }
  img {
    width: ${t.value.qrSizeMm}mm;
    height:${t.value.qrSizeMm}mm;
    object-fit: contain;
    image-rendering: pixelated;
  }
  @media print {
    .grid { padding: 0; }
  }
</style>
</head>
<body>
  <div class="grid">
    ${m.value.map(a=>`
      <div class="cell">
        <div style="display:block; text-align:center">
          <img src="${a.qr}" alt="QR">
          <div style="font-size:2mm; padding-left: 1mm; padding-right: 1mm; margin-bottom:2mm">${a.description&&t.value.displayDescription?a.description:""}</div>
        </div>
      </div>`).join("")}
  </div>
</body>
</html>`;s.open(),s.write(l),s.close()},M=async()=>{i.value=!0,n.value=0;const s=p.objects.map(a=>I.get(`/component/qr-generator/qr-code-base64/${a.type}/${a.id}`,p.params).then(_=>(n.value++,{qr:_.data.qr,description:a.description})).catch(_=>(console.error("QR load error:",a,_),null))),l=await Promise.all(s);i.value=!1,m.value=l.filter(Boolean),$()},k=()=>{const s=r.value.contentWindow;s&&(s.focus(),s.print())},z=()=>{m.value=[],i.value=!1};return f(()=>p.objects,()=>{M()},{deep:!0}),f(t,()=>{$()},{deep:!0}),S(()=>{M()}),P(()=>{z()}),h({printQrs:k}),(s,l)=>(v(),g("div",J,[e("div",K,[e("div",X,[u.objects.length===0?(v(),g("h3",Y,[l[4]||(l[4]=e("i",{class:"mdi mdi-qrcode-remove"},null,-1)),U(" "+o(s.$t("no_objects_to_print")),1)])):x("",!0),i.value&&u.objects.length!=0?(v(),g("div",Z,[D(B),e("div",ee,o(n.value)+"/"+o(u.objects.length),1),e("div",te,[e("div",{class:"progress-bar progress-bar-animated bg-info",role:"progressbar",style:E({width:y.value+"%"}),"aria-valuenow":y.value,"aria-valuemin":"0","aria-valuemax":"100"},o(y.value)+"% ",13,le)])])):x("",!0)])]),d(e("div",se,[e("div",ae,[e("b",null,o(s.$t("printing_parameters")),1)]),e("div",oe,[e("small",null,o(s.$t("qr_size_box_mm")),1),l[5]||(l[5]=e("br",null,null,-1)),d(e("input",{class:"form-control",type:"number","onUpdate:modelValue":l[0]||(l[0]=a=>t.value.qrSizeMm=a)},null,512),[[b,t.value.qrSizeMm]])]),e("div",ne,[e("small",null,o(s.$t("page_margin_mm")),1),l[6]||(l[6]=e("br",null,null,-1)),d(e("input",{class:"form-control",type:"number","onUpdate:modelValue":l[1]||(l[1]=a=>t.value.pageMarginMm=a)},null,512),[[b,t.value.pageMarginMm]])]),e("div",ie,[e("small",null,o(s.$t("gap_mm")),1),l[7]||(l[7]=e("br",null,null,-1)),d(e("input",{class:"form-control",type:"number","onUpdate:modelValue":l[2]||(l[2]=a=>t.value.gapMm=a)},null,512),[[b,t.value.gapMm]])]),e("div",re,[e("small",null,o(s.$t("display_description")),1),l[9]||(l[9]=e("br",null,null,-1)),e("label",de,[d(e("input",{type:"checkbox","onUpdate:modelValue":l[3]||(l[3]=a=>t.value.displayDescription=a)},null,512),[[w,t.value.displayDescription]]),l[8]||(l[8]=e("span",{class:"slider"},null,-1))])])],512),[[q,!i.value&&m.value.length!==0&&u.objects.length!=0]]),d(e("div",ue,[e("div",me,[e("div",pe,o(s.$t("printing_preview")),1)]),e("div",ce,[e("iframe",{ref_key:"printFrame",ref:r,class:"preview"},null,512)])],512),[[q,!i.value&&m.value.length!==0&&u.objects.length!=0]])]))}}),he=V(ve,[["__scopeId","data-v-5a2f3872"]]);export{he as Q,be as _};
