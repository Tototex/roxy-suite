// Mocked DOM/network execution; not evidence of a camera/NFC browser scan.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const root=process.argv[2]||path.resolve(__dirname,'..');
let code=fs.readFileSync(path.join(root,'includes/modules/show-tickets/assets/js/door-mode.js'),'utf8');
code=code.replace(/\}\)\(\);\s*$/,'window.memberFixture={validateToken,doMemberAdmission};})();');
class Element {
  constructor(){this.children=[];this.dataset={};this.value='50';this.options=[{text:'Fixture'}];this.selectedIndex=0;this.classList={add(){},remove(){},toggle(){}};this.listeners={};}
  addEventListener(name,fn){this.listeners[name]=fn;}setAttribute(){}
  querySelectorAll(){return [];}querySelector(selector){return selector==='.roxy-door-result-actions'?actions:null;}
  prepend(child){this.children.unshift(child);}appendChild(child){this.children.push(child);}getContext(){return {};}
}
const elements={},actions=new Element();
for(const id of ['video','start','auto-resume','camera-note','overlay-text','result','modal','showing-lock','attendance'])elements['roxy-door-'+id]=new Element();
elements['roxy-door-auto-resume'].checked=false;
const requests=[];
const payload={credential_type:'member',found:true,status:'valid',subscription_id:1,membership_qty:3,member_name:'Fixture'};
const document={body:new Element(),addEventListener(){},getElementById(id){return elements[id]||null;},createElement(){return new Element();}};
const window={RoxyDoorMode:{ajaxUrl:'fixture',nonce:'fixture',checkInNonce:'fixture'},addEventListener(){},setTimeout(){}};
const context={window,document,navigator:{},URLSearchParams,console,setTimeout(){},clearTimeout(){},setInterval(){},clearInterval(){},fetch:async(url,opts)=>{const request=Object.fromEntries(new URLSearchParams(opts.body));requests.push(request);return {json:async()=>({success:true,data:request.action==='roxy_st_door_stats'?{}:{...payload,...(request.action==='roxy_st_member_admit'||request.auto_admit==='1'?{admitted:true,admit_quantity:1}:{})}})};}};
vm.runInNewContext(code,context);
(async()=>{
  requests.length=0;await window.memberFixture.validateToken('1');
  assert.equal(requests.length,1);assert.equal(requests[0].auto_admit,'0');
  assert.equal(actions.children[0].textContent,'Admit Member');
  console.log('PASS: Auto Admit off sends verification only and renders explicit member button');
  await actions.children[0].listeners.click();
  assert.equal(requests[1].action,'roxy_st_member_admit');assert.equal(requests[1].quantity,'1');
  console.log('PASS: explicit member click uses protected admission endpoint once');
  elements['roxy-door-auto-resume'].checked=true;requests.length=0;
  await window.memberFixture.validateToken('1');assert.equal(requests.length,1);assert.equal(requests[0].auto_admit,'1');
  console.log('PASS: Auto Admit on explicitly requests admission');
})().catch(e=>{console.error(e);process.exitCode=1;});
