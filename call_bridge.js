(function(){
  const KEY='mawared_macrodroid_webhook';
  const HANG_KEY='mawared_macrodroid_hangup_webhook';
  function isMobile(){ return /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent) || matchMedia('(pointer:coarse)').matches; }
  function normalize(n){ return String(n||'').replace(/\D/g,''); }
  function buildUrl(tpl,num){
    let u=String(tpl||'').trim(); if(!u) return '';
    if(u.includes('{number}')) return u.replaceAll('{number}',encodeURIComponent(num));
    const sep=u.includes('?')?'&':'?';
    return u+sep+'number='+encodeURIComponent(num)+'&phone='+encodeURIComponent(num);
  }
  function save(url){ localStorage.setItem(KEY,String(url||'').trim()); }
  function get(){ return localStorage.getItem(KEY)||''; }
  function getHang(){ return localStorage.getItem(HANG_KEY)||''; }
  function fireUrl(url){ if(!url) return false; try{ const img=new Image(); img.src=url+(url.includes('?')?'&':'?')+'_t='+Date.now(); return true; }catch(e){ return false; } }
  function openSettings(){
    let m=document.getElementById('macroCallSettings');
    if(!m){
      m=document.createElement('div'); m.id='macroCallSettings'; m.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;font-family:inherit';
      m.innerHTML='<div style="width:100%;max-width:520px;background:#fff;color:#111;border-radius:16px;padding:20px;box-shadow:0 20px 60px #0005"><h3 style="margin:0 0 8px">⚙️ إعداد الاتصال من الكمبيوتر</h3><p style="font-size:13px;color:#666;line-height:1.7">ضع رابط MacroDroid للاتصال ورابطاً آخر لإنهاء المكالمة. يمكنك استخدام <b>{number}</b> داخل أي رابط.</p><label style="display:block;font-size:12px;font-weight:800;margin:10px 0 5px">Webhook بدء المكالمة</label><input id="macroWebhookInput" style="width:100%;padding:12px;border:1px solid #ddd;border-radius:10px;direction:ltr" placeholder="https://trigger.macrodroid.com/.../{number}"><label style="display:block;font-size:12px;font-weight:800;margin:12px 0 5px">Webhook إنهاء المكالمة</label><input id="macroHangupInput" style="width:100%;padding:12px;border:1px solid #ddd;border-radius:10px;direction:ltr" placeholder="https://trigger.macrodroid.com/.../{number}"><div style="display:flex;gap:8px;margin-top:12px;justify-content:flex-end;flex-wrap:wrap"><button id="macroCancel" style="padding:10px 14px">إلغاء</button><button id="macroTest" style="padding:10px 14px">اختبار الاتصال</button><button id="macroTestHang" style="padding:10px 14px">اختبار الإنهاء</button><button id="macroSave" style="padding:10px 14px;background:#173B27;color:#fff;border:0;border-radius:8px">حفظ</button></div></div>';
      document.body.appendChild(m);
      document.getElementById('macroCancel').onclick=()=>m.remove();
      document.getElementById('macroSave').onclick=()=>{save(document.getElementById('macroWebhookInput').value);localStorage.setItem(HANG_KEY,document.getElementById('macroHangupInput').value.trim());m.remove();alert('تم حفظ Webhook الاتصال والإنهاء');};
      document.getElementById('macroTest').onclick=()=>{const u=buildUrl(document.getElementById('macroWebhookInput').value,'01000000000');if(!u)return alert('ضع رابط Webhook الاتصال أولاً');fire(u);alert('تم إرسال اختبار الاتصال');};
      document.getElementById('macroTestHang').onclick=()=>{const u=buildUrl(document.getElementById('macroHangupInput').value,'01000000000');if(!u)return alert('ضع رابط Webhook الإنهاء أولاً');fireUrl(u);alert('تم إرسال اختبار الإنهاء');};
    }
    document.getElementById('macroWebhookInput').value=get();
    document.getElementById('macroHangupInput').value=getHang();
  }
  function fire(url){ fireUrl(url); }
  window.callViaMacroDroid=function(phone){
    const num=normalize(phone); if(!num) return;
    if(isMobile()){ window.location.href='tel:'+num; return; }
    const tpl=get(); if(!tpl){ openSettings(); return; }
    const url=buildUrl(tpl,num); fire(url);
    const old=document.getElementById('macroCallToast'); if(old) old.remove();
    const t=document.createElement('div'); t.id='macroCallToast'; t.textContent='📞 تم إرسال أمر الاتصال للهاتف عبر MacroDroid'; t.style.cssText='position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:#173B27;color:#fff;padding:12px 18px;border-radius:10px;z-index:10000;font-weight:700;box-shadow:0 8px 30px #0004'; document.body.appendChild(t); setTimeout(()=>t.remove(),2600);
  };
  window.hangupViaMacroDroid=function(phone){
    const num=normalize(phone);
    if(!num) return;
    const tpl=getHang();
    if(!tpl){ openSettings(); return; }
    fireUrl(buildUrl(tpl,num));
    const old=document.getElementById('macroHangToast'); if(old) old.remove();
    const t=document.createElement('div'); t.id='macroHangToast'; t.textContent='📴 تم إرسال أمر إنهاء المكالمة عبر MacroDroid'; t.style.cssText='position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:#7f1d1d;color:#fff;padding:12px 18px;border-radius:10px;z-index:10000;font-weight:700;box-shadow:0 8px 30px #0004'; document.body.appendChild(t); setTimeout(()=>t.remove(),2600);
  };
  window.openMacroDroidSettings=openSettings;
})();
