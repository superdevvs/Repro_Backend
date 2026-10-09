<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Repro Copilot</title>
<style>
:root{color-scheme:light dark;--bg:light-dark(#fff,#202422);--fg:light-dark(#17231d,#edf3ef);--muted:light-dark(#56685e,#b3c2b9);--soft:light-dark(#f3f6f4,#2d3430);--line:light-dark(#dbe4de,#48564d);--accent:light-dark(#176347,#94dfb9);--accent-text:light-dark(#fff,#17231d)}
*{box-sizing:border-box}body{margin:0;color:var(--fg);background:var(--bg);font:14px/1.5 system-ui,sans-serif}main{padding:20px;max-width:900px;margin:auto}header{display:flex;align-items:center;gap:10px;margin-bottom:18px}.logo{display:grid;place-items:center;width:34px;height:34px;background:var(--accent);color:var(--accent-text);border-radius:10px;font-weight:600}h1{font-size:16px;margin:0}h2{font-size:15px;margin:18px 0 8px}.muted{color:var(--muted);font-size:12px}form,.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}input[type=search]{min-width:0;flex:1}input,button{font:inherit;color:var(--fg);border:1px solid var(--line);border-radius:9px;padding:10px;background:var(--bg)}button{cursor:pointer}button:disabled{cursor:default;opacity:.55}button.primary{background:var(--accent);color:var(--accent-text);border-color:var(--accent)}button:focus-visible,a:focus-visible,input:focus-visible{outline:2px solid var(--accent);outline-offset:3px}a{color:var(--accent)}.record{padding:14px 0;border-bottom:1px solid var(--line)}.record strong{font-weight:600}.record .actions{margin-top:9px}.status{background:var(--soft);padding:4px 8px;border-radius:7px;font-size:12px}.review{background:var(--soft);border-radius:12px;padding:16px}dl{margin:0}dl>div{display:grid;grid-template-columns:minmax(100px,1fr) minmax(0,2fr);gap:12px;padding:7px 0;border-bottom:1px solid var(--line)}dt{color:var(--muted)}dd{margin:0;overflow-wrap:anywhere;white-space:pre-wrap}ul{padding-left:20px;margin:5px 0}label.confirm{display:flex;gap:10px;align-items:flex-start;margin:16px 0}label.confirm input{margin-top:4px}.error{padding:12px;background:var(--soft);border-left:3px solid var(--accent)}#message{margin:12px 0}pre{white-space:pre-wrap;overflow-wrap:anywhere;font:inherit;margin:5px 0}.receipt{padding:14px;background:var(--soft);border-radius:12px}footer{margin-top:18px}.empty{padding:24px 0;color:var(--muted)}@media(max-width:420px){main{padding:14px}dl>div{grid-template-columns:1fr;gap:3px}input,button{font-size:16px}.actions button{min-height:44px}}
</style></head><body><main>
<header><div class="logo" aria-hidden="true">R</div><div><h1>Repro Copilot</h1><div class="muted">Your connected Repro workspace</div></div></header>
<form id="search-form"><label for="search" class="muted">Find a shoot</label><input id="search" type="search" maxlength="200" placeholder="Address, city or shoot ID" required><button type="submit">Search</button></form>
<div id="message" role="status" aria-live="polite"></div><section id="content" aria-live="polite"><div class="empty">Ask about a shoot, compare availability, or prepare a booking in the conversation.</div></section>
<footer class="muted">Actions use your Repro permissions. <a id="connections" href="https://reprodashboard.com/copilot/connections" target="_blank" rel="noopener noreferrer">Manage connection</a></footer>
</main>
<script>
(() => {
  const root=document.querySelector('main'), content=document.getElementById('content'), message=document.getElementById('message');
  const pending=new Map(); let sequence=0, ready=false, hostOrigin=null;
  const label=value=>String(value).replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase());
  function el(tag,text,className){const node=document.createElement(tag);if(text!==undefined)node.textContent=String(text);if(className)node.className=className;return node;}
  function notify(method,params){window.parent.postMessage({jsonrpc:'2.0',method,params},hostOrigin||'*');}
  function rpc(method,params){return new Promise((resolve,reject)=>{const id=++sequence;const timer=setTimeout(()=>{pending.delete(id);reject(new Error('The request timed out. Check the stored action outcome before retrying.'));},45000);pending.set(id,{resolve,reject,timer});window.parent.postMessage({jsonrpc:'2.0',id,method,params},hostOrigin||'*');});}
  function safeLink(url,text){try{const parsed=new URL(url);if(parsed.protocol!=='https:'||parsed.hostname!=='reprodashboard.com'||parsed.username||parsed.password)return null;const link=el('a',text);link.href=parsed.href;link.target='_blank';link.rel='noopener noreferrer';return link;}catch{return null;}}
  function valueNode(value,depth=0){
    if(value===null||value===undefined)return el('span','Not recorded');
    if(typeof value!=='object')return el('span',typeof value==='boolean'?(value?'Yes':'No'):value);
    if(Array.isArray(value)){const list=el('ul');value.forEach(item=>{const li=el('li');li.append(valueNode(item,depth+1));list.append(li);});return list;}
    const dl=el('dl');Object.entries(value).forEach(([key,item])=>{const row=el('div');row.append(el('dt',label(key)));const dd=el('dd');dd.append(valueNode(item,depth+1));row.append(dd);dl.append(row);});return dl;
  }
  async function call(name,args){if(!ready)throw new Error('This card is still connecting to ChatGPT.');message.textContent='Loading…';try{const result=await rpc('tools/call',{name,arguments:args});render(result);return result;}catch(error){message.textContent=error.message;throw error;}}
  function record(item){const row=el('article',undefined,'record');row.append(el('strong',item.title||item.address||item.name||('Shoot '+item.id)));if(item.status)row.append(el('div',item.status,'muted'));const actions=el('div',undefined,'actions');const id=String(item.id||'');if(/^shoot:\d+$/.test(id)||Number.isInteger(item.id)){const inspect=el('button','Inspect');inspect.type='button';inspect.addEventListener('click',()=>call('fetch',{id:id.startsWith('shoot:')?id:'shoot:'+id}).catch(()=>{}));actions.append(inspect);}const link=safeLink(item.url||item.source_url,'Open in Repro');if(link)actions.append(link);row.append(actions);if(item.observed_blockers)row.append(valueNode(item.observed_blockers));return row;}
  function render(response){
    const data=response.structuredContent||{};message.textContent='';content.replaceChildren();
    if(response.isError||data.error){const error=el('div',data.error?.message||response.content?.[0]?.text||'This operation could not be completed.','error');error.setAttribute('role','alert');content.append(error);if(data.error?.fields)content.append(valueNode(data.error.fields));return;}
    if(data.draft_id&&data.review&&data.status==='prepared'){
      content.append(el('h2','Review proposed action'));const review=el('div',undefined,'review');review.append(valueNode(data.review));content.append(review);
      content.append(el('p','Preview expires '+new Date(data.expires_at).toLocaleString()+'. Nothing has been submitted.','muted'));
      if(data.review_changed){content.append(el('p','This preview changed. Prepare and review a new action.','error'));return;}
      const confirm=el('label',undefined,'confirm'),check=el('input');check.type='checkbox';confirm.append(check,el('span','I approve these exact changes and the listed notification effects.'));content.append(confirm);
      const button=el('button','Confirm action','primary');button.type='button';button.disabled=true;check.addEventListener('change',()=>{button.disabled=!check.checked;});
      button.addEventListener('click',async()=>{button.disabled=true;check.disabled=true;try{await call('commit_action',{draft_id:data.draft_id,review_hash:data.review_hash});}catch{const reconcile=el('button','Check stored outcome');reconcile.type='button';reconcile.addEventListener('click',()=>call('get_draft',{draft_id:data.draft_id}).catch(()=>{}));content.append(reconcile);}});content.append(button);return;
    }
    if(data.draft_id){content.append(el('h2','Action outcome'));content.append(valueNode(data.result||data));if(data.status==='processing'){const button=el('button','Check stored outcome');button.type='button';button.addEventListener('click',()=>call('get_draft',{draft_id:data.draft_id}).catch(()=>{}));content.append(button);}return;}
    if(data.results||Array.isArray(data.data)){
      const rows=data.results||data.data;if(!rows.length)content.append(el('p','No matching records.','empty'));else rows.forEach(item=>{
        if(item.source_url||item.url||item.address)content.append(record(item));
        else {const row=el('article',undefined,'record');row.append(valueNode(item));content.append(row);}
      });
      Object.entries(data).filter(([key])=>!['data','results','total','has_more','truncated','page'].includes(key)).forEach(([key,value])=>{content.append(el('h2',label(key)),valueNode(value));});
      if(data.total!==undefined)content.append(el('p',rows.length+' shown · '+data.total+' total'+(data.has_more||data.truncated?' · More results available':''),'muted'));return;
    }
    if(data.shoot){content.append(el('h2',data.shoot.address||'Shoot details'));content.append(valueNode(data.shoot));const link=safeLink(data.url||data.shoot.source_url,'Open shoot in Repro');if(link)content.append(link);Object.entries(data).filter(([key])=>!['shoot','id','title','text','url','media_url'].includes(key)).forEach(([key,value])=>{content.append(el('h2',label(key)),valueNode(value));});return;}
    if(data.name&&data.email){content.append(el('h2',data.name),el('p',data.email));content.append(valueNode({role:data.role,scopes:data.scopes}));return;}
    content.append(valueNode(data));
  }
  window.addEventListener('message',event=>{
    if(event.source!==window.parent)return;const msg=event.data;if(!msg||msg.jsonrpc!=='2.0')return;
    if(!hostOrigin&&event.origin!=='null')hostOrigin=event.origin;
    if(Object.prototype.hasOwnProperty.call(msg,'id')&&pending.has(msg.id)){const p=pending.get(msg.id);clearTimeout(p.timer);pending.delete(msg.id);if(msg.error)p.reject(new Error(msg.error.message||'Host request failed.'));else p.resolve(msg.result);return;}
    if(msg.method==='ui/notifications/tool-result')render(msg.params);
    if(msg.method==='ui/notifications/host-context-changed'){const theme=msg.params?.theme;if(theme==='dark'||theme==='light')document.documentElement.style.colorScheme=theme;}
  });
  document.getElementById('search-form').addEventListener('submit',event=>{event.preventDefault();call('search',{query:document.getElementById('search').value.trim()}).catch(()=>{});});
  if(window.parent!==window)rpc('ui/initialize',{appInfo:{name:'repro-copilot',version:'1.0.0'},appCapabilities:{},protocolVersion:'2026-01-26'}).then(result=>{ready=true;const theme=result?.hostContext?.theme;if(theme==='dark'||theme==='light')document.documentElement.style.colorScheme=theme;notify('ui/notifications/initialized',{});}).catch(()=>{message.textContent='Use the conversation for this workflow; the interactive card could not connect.';});
  else message.textContent='Preview only. Connect this plugin in ChatGPT to use live records.';
})();
</script></body></html>
