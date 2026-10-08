// Mocked DOM/network execution; not evidence of a camera/NFC browser scan.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const root=process.argv[2]||path.resolve(__dirname,'..');
let code=fs.readFileSync(path.join(root,'includes/modules/show-tickets/assets/js/door-mode.js'),'utf8');
code=code.replace(/\}\)\(\);\s*$/,'window.memberFixture={validateToken,doMemberAdmission,doMemberWalkupUndo};})();');
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
let delayed=0;
const window={RoxyDoorMode:{ajaxUrl:'fixture',nonce:'fixture',checkInNonce:'fixture'},addEventListener(){},setTimeout(){delayed++;}};
const context={window,document,navigator:{},URLSearchParams,console,setTimeout(){},clearTimeout(){},setInterval(){},clearInterval(){},fetch:async(url,opts)=>{const request=Object.fromEntries(new URLSearchParams(opts.body));requests.push(request);return {json:async()=>({success:true,data:request.action==='roxy_st_door_stats'?{}:request.action==='roxy_st_member_walkup_undo'?{audit:{replacement_visit_id:201},attendance:{}}:{...payload,...(request.action==='roxy_st_member_admit'||request.auto_admit==='1'?{admitted:true,admit_quantity:1,walkup_visit_id:200,admit_showing_id:50}:{})}})};}};
window.confirm=()=>true;
vm.runInNewContext(code,context);
(async()=>{
  requests.length=0;await window.memberFixture.validateToken('1');
  assert.equal(requests.length,1);assert.equal(requests[0].auto_admit,'0');
  assert.equal(actions.children[0].textContent,'Admit Member');
  console.log('PASS: Auto Admit off sends verification only and renders explicit member button');
  await actions.children[0].listeners.click();
  assert.equal(requests[1].action,'roxy_st_member_admit');assert.equal(requests[1].quantity,'1');
  console.log('PASS: explicit member click uses protected admission endpoint once');
  requests.length=0;await window.memberFixture.doMemberWalkupUndo({walkup_visit_id:200,subscription_id:1,admit_showing_id:50,admit_quantity:2});
  assert.equal(requests[0].action,'roxy_st_member_walkup_undo');assert.equal(requests[0].visit_id,'200');assert.equal(requests[0].subscription_id,'1');assert.equal(requests[0].showing_id,'50');
  console.log('PASS: member walk-up Undo posts the exact visit, subscription, and showing identities');
  elements['roxy-door-auto-resume'].checked=true;requests.length=0;delayed=0;
  await window.memberFixture.validateToken('1');assert.equal(requests.length,1);assert.equal(requests[0].auto_admit,'1');
  assert.equal(delayed,0);
  console.log('PASS: Auto Admit keeps the walk-up Undo control available instead of dismissing the admission');
  const retryPayload={walkup_visit_id:205,subscription_id:1,admit_showing_id:50,admit_quantity:2};
  context.fetch=async(url,opts)=>{const request=Object.fromEntries(new URLSearchParams(opts.body));requests.push(request);return {json:async()=>({success:false,data:{message:'Stale visit; refresh and review.'}})};};
  requests.length=0;await window.memberFixture.doMemberWalkupUndo(retryPayload);
  assert.equal(retryPayload.walkup_visit_id,205);assert.equal(retryPayload.admit_quantity,2);assert.equal(requests.length,1);
  console.log('PASS: failed walk-up Undo preserves the exact visit identity and original arrival quantity for safe review/retry');
  let finishUndo;
  context.fetch=(url,opts)=>{const request=Object.fromEntries(new URLSearchParams(opts.body));requests.push(request);return new Promise(resolve=>{finishUndo=()=>resolve({json:async()=>({success:true,data:{audit:{replacement_visit_id:301},attendance:{}}})});});};
  requests.length=0;const firstUndo=window.memberFixture.doMemberWalkupUndo(retryPayload);await Promise.resolve();
  await window.memberFixture.doMemberWalkupUndo(retryPayload);
  assert.equal(requests.length,1);assert.equal(requests[0].visit_id,'205');finishUndo();await firstUndo;
  assert.equal(retryPayload.walkup_visit_id,301);assert.equal(retryPayload.admit_quantity,1);
  console.log('PASS: concurrent/repeated Undo clicks issue one request and retain only the returned replacement visit identity');
  requests.length=0;context.fetch=async(url,opts)=>{const request=Object.fromEntries(new URLSearchParams(opts.body));requests.push(request);return {json:async()=>({success:true,data:{audit:{},attendance:{}}})};};
  await window.memberFixture.doMemberWalkupUndo(retryPayload);
  assert.equal(requests.length,1);assert.equal(requests[0].visit_id,'301');assert.equal(retryPayload.walkup_visit_id,undefined);assert.equal(retryPayload.admit_quantity,0);
  console.log('PASS: final Undo targets the replacement visit and clears its identity only after confirmed success');
})().catch(e=>{console.error(e);process.exitCode=1;});
