const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('includes/modules/will-call/roxy-will-call.php', 'utf8');
for (const match of source.matchAll(/<script>([\s\S]*?)<\/script>/g)) {
  new vm.Script(match[1].replace(/<\?php[\s\S]*?\?>/g, '"fixture"'));
  console.log('PASS: Will Call inline JavaScript compiles');
}

function check(ok, label) { if (!ok) throw new Error(label); console.log('PASS: '+label); }
const storage = new Map();
const callbacks = {};
const used = { value:'1', disabled:false };
const checked = { checked:true, disabled:false };
const hostile = '<img src=x onerror=alert(1)>';
const attrs = { 'data-context-id':'8','data-queue-owner':'owner','data-customer-key':'buyer','data-qty':'2','data-confirmed-used':'1','data-readonly':'0','data-ticket-types':JSON.stringify({[hostile]:2}) };
const row = { getAttribute:k=>attrs[k],setAttribute:(k,v)=>attrs[k]=v,querySelector:s=>s==='input.roxy-used'?used:s==='input.roxy-checked'?checked:null,querySelectorAll:()=>[used,checked],classList:{toggle(){}} };
const table = {getAttribute:k=>attrs[k],querySelectorAll:()=>[row],querySelector:()=>row,addEventListener:(k,fn)=>callbacks[k]=fn};
const nodes={};
function node() { return {children:[],textContent:'',style:{},classList:{toggle(){}},append(...items){this.children.push(...items);},replaceChildren(...items){this.children=items;}}; }
for(const key of ['#roxy-wc-checked-in','#roxy-wc-remaining','#roxy-wc-total-sold','#roxy-wc-type-breakdown','#roxy-wc-offline-bar','#roxy-wc-offline-status','#roxy-wc-offline-detail'])nodes[key]=node();
let start;
const context=vm.createContext({
  document:{addEventListener:(k,fn)=>start=fn,querySelector:s=>s==='.roxy-wc-table'?table:nodes[s]??null,getElementById:s=>nodes['#'+s]??null,createElement:()=>node(),createTextNode:text=>({literal:text})},
  window:{addEventListener(){},setTimeout(){},confirm:()=>true}, navigator:{onLine:false},
  localStorage:{getItem:k=>storage.get(k),setItem:(k,v)=>storage.set(k,v)},
  crypto:{randomUUID:()=>String(Math.random())},Date, console,
});
let substitutions=0;
let script=source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/<\?php[\s\S]*?\?>/g,()=>JSON.stringify(['queue','state'][substitutions++]));
script=script.replace('      applyPersistedState();','      window.test={readQueue,upsertQueueItem,removeQueueItem,applyPersistedState};\n      applyPersistedState();');
vm.runInContext(script,context);start();
const api=context.window.test;
const first={context_id:8,customer_key:'buyer',owner:'owner',issued_at:Date.now(),operation_id:'one',used_qty:1};
const second={...first,operation_id:'two',used_qty:2};
api.upsertQueueItem(first);api.upsertQueueItem(second);api.removeQueueItem(first);
check(api.readQueue().length===1 && api.readQueue()[0].operation_id==='two','older save completion cannot remove newer queued operation');
api.removeQueueItem(second);check(api.readQueue().length===0,'matching operation removed after success');
api.upsertQueueItem({...first,issued_at:Date.now()-3*60*60*1000});check(api.readQueue().length===0,'expired queue is not replayed');
api.upsertQueueItem({...first,owner:'another-staff-session'});check(api.readQueue().length===0,'other staff session queue is not replayed');
storage.set('state:v2:owner',JSON.stringify({'8:buyer':{used_qty:0,checked_in:0}}));used.value='1';api.applyPersistedState();
check(used.value==='1','saved browser state without pending operation cannot replace server count');
const labelNode=nodes['#roxy-wc-type-breakdown'].children[0]?.children[1];
check(labelNode?.literal===' '+hostile+' checked in','hostile ticket label remains literal text, never HTML');
(async()=>{
  checked.checked=true;attrs['data-confirmed-used']='0';
  await callbacks.change({target:{closest:()=>row,matches:()=>true,checked:true}});
  check(used.value==='2' && api.readQueue()[0]?.used_qty===2,'checkbox explicitly admits full quantity rather than being ignored');
  attrs['data-readonly']='1';used.value='2';
  await callbacks.change({target:{closest:()=>row,matches:()=>true,checked:false}});
  check(used.value==='2','member walk-up row is read-only');
})().catch(error=>{console.error(error);process.exitCode=1;});
