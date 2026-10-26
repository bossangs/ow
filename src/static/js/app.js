(function(){
function qs(s,c){return (c||document).querySelector(s)}
document.addEventListener('click',function(e){
 var copy=e.target.closest('[data-copy]');if(copy){var el=qs(copy.getAttribute('data-copy'));if(el){if(navigator.clipboard)navigator.clipboard.writeText(el.value||el.textContent);copy.innerHTML='已复制';setTimeout(function(){copy.innerHTML='复制'},1400);}}
 var cap=e.target.closest('.captcha-img');if(cap){cap.src=cap.src.split('&_t=')[0]+'&_t='+Date.now();}
 var confirmEl=e.target.closest('[data-confirm]');if(confirmEl&&!window.confirm(confirmEl.getAttribute('data-confirm'))){e.preventDefault();}
 var b=e.target.closest('[data-coupon-apply]');if(!b)return;e.preventDefault();var f=b.closest('[data-coupon-form]'),code=qs('[data-coupon-code]',f),out=qs('[data-coupon-result]',f);if(!code||!code.value){out.textContent='请输入优惠码';return;}var fd=new FormData(f);fd.append('type',f.getAttribute('data-coupon-type'));b.disabled=true;out.textContent='正在校验…';fetch(f.getAttribute('data-coupon-url'),{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(d){if(d.code===0&&d.data){var x=d.data;out.innerHTML='原价 '+x.amount+' · 会员折扣 '+x.tier_discount+' · 优惠码优惠 '+x.coupon_discount+' · <b>应付 '+x.pay_amount+'</b>';out.className='form-help mt-2 text-success';}else{out.textContent=d.msg||'优惠码不可用';out.className='form-help mt-2 text-danger';}}).catch(function(){out.textContent='校验失败，请直接提交订单重试';out.className='form-help mt-2 text-danger';}).finally(function(){b.disabled=false;});
});
document.addEventListener('change',function(e){var s=e.target;if(s.matches('[data-driver-fields]'))document.querySelectorAll('[data-driver-panel]').forEach(function(p){p.classList.toggle('d-none',p.getAttribute('data-driver-panel')!==s.value);});});
document.addEventListener('click',function(e){var b=e.target.closest('[data-sendcode]');if(!b)return;e.preventDefault();var email=qs(b.getAttribute('data-email')||'[name=email]'),captcha=qs(b.getAttribute('data-captcha')||'[name=captcha]');var fd=new FormData();fd.append('_token',b.getAttribute('data-token')||'');fd.append('email',email?email.value:'');fd.append('captcha',captcha?captcha.value:'');fd.append('scene',b.getAttribute('data-scene')||'register');b.disabled=true;fetch(b.getAttribute('data-sendcode'),{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(d){alert(d.msg||'已发送');}).finally(function(){setTimeout(function(){b.disabled=false},60000)});});
})();
/* root 密码工具：显示/隐藏、随机生成、强度检测（[data-pw-tools] 容器内） */
(function(){
  function score(v){var c=0;if(/[a-z]/.test(v))c++;if(/[A-Z]/.test(v))c++;if(/[0-9]/.test(v))c++;if(/[^A-Za-z0-9]/.test(v))c++;var s=0;if(v.length>=8)s+=25;if(v.length>=12)s+=15;if(v.length>=16)s+=10;s+=c*12.5;if(/\s|['"`\\]/.test(v))s=Math.min(s,10);return {s:Math.min(100,s),c:c};}
  function gen(){var sets=['ABCDEFGHJKLMNPQRSTUVWXYZ','abcdefghijkmnpqrstuvwxyz','23456789','@#%+=_-'];var all=sets.join(''),out=[],r=new Uint32Array(16);(window.crypto||window.msCrypto).getRandomValues(r);for(var i=0;i<16;i++){var src=i<4?sets[i]:all;out.push(src.charAt(r[i]%src.length));}for(var j=out.length-1;j>0;j--){var k=r[j]%(j+1),t=out[j];out[j]=out[k];out[k]=t;}return out.join('');}
  function update(box){var inp=box.querySelector('input'),bar=box.querySelector('[data-pw-bar]'),hint=box.querySelector('[data-pw-hint]');if(!inp||!bar)return;var v=inp.value,r=score(v),ok=v.length>=8&&v.length<=32&&r.c>=3&&!/\s|['"`\\]/.test(v);bar.style.width=(v?r.s:0)+'%';bar.className='progress-bar '+(r.s<50?'bg-danger':(r.s<75?'bg-warning':'bg-success'));if(hint){hint.textContent=!v?'8-32 位，需包含大写字母、小写字母、数字、符号中的至少三类；不要包含空格和引号。':(ok?(r.s>=75?'强度：强':'强度：中，建议更长或加入符号'):'强度不足：需 8-32 位且至少包含三类字符，不能有空格或引号');hint.className='form-help mt-1 '+(v&&!ok?'text-danger':'');}inp.setCustomValidity(v&&!ok?'密码强度不足':'');}
  document.addEventListener('input',function(e){var box=e.target.closest&&e.target.closest('[data-pw-tools]');if(box)update(box);});
  document.addEventListener('click',function(e){var t=e.target.closest&&e.target.closest('[data-pw-toggle],[data-pw-generate]');if(!t)return;var box=t.closest('[data-pw-tools]'),inp=box&&box.querySelector('input');if(!inp)return;e.preventDefault();if(t.hasAttribute('data-pw-toggle')){var show=inp.type==='password';inp.type=show?'text':'password';t.textContent=show?'隐藏':'显示';}else{inp.value=gen();inp.type='text';var tg=box.querySelector('[data-pw-toggle]');if(tg)tg.textContent='隐藏';update(box);}});
})();
/* 复制按钮上携带的文本（恢复码、密钥等） */
document.addEventListener('click', function (e) {
  var b = e.target.closest ? e.target.closest('[data-copy-text]') : null;
  if (!b) { return; }
  var t = b.getAttribute('data-copy-text'), old = b.innerHTML;
  var done = function () { b.innerHTML = '已复制'; setTimeout(function () { b.innerHTML = old; }, 1400); };
  if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t).then(done); return; }
  var ta = document.createElement('textarea'); ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0';
  document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (x) {} document.body.removeChild(ta);
});
