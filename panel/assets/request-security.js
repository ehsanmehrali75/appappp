(() => {
    'use strict';
    const token=document.querySelector('meta[name="app-csrf"]')?.content;
    if(!token)return;
    const local=url=>{try{return new URL(url,location.href).origin===location.origin;}catch{return false;}};
    const mutates=method=>!['GET','HEAD','OPTIONS'].includes(String(method||'GET').toUpperCase());
    const originalFetch=window.fetch;
    window.fetch=function(resource,init={}) {
        const request=resource instanceof Request?resource:null;
        if(local(request?.url||resource)&&mutates(init.method||request?.method)) {
            const headers=new Headers(request?.headers);new Headers(init.headers).forEach((v,k)=>headers.set(k,v));
            headers.set('X-CSRF-Token',token);init={...init,headers};
        }
        return originalFetch.call(this,resource,init);
    };
    const open=XMLHttpRequest.prototype.open,send=XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open=function(method,url,...args){this._appCsrf=local(url)&&mutates(method);return open.call(this,method,url,...args);};
    XMLHttpRequest.prototype.send=function(...args){if(this._appCsrf)this.setRequestHeader('X-CSRF-Token',token);return send.apply(this,args);};
    document.addEventListener('submit',event=>{
        const form=event.target;
        if(!(form instanceof HTMLFormElement))return;
        const submitter=event.submitter;
        const action=submitter?.hasAttribute('formaction')?submitter.formAction:form.action;
        const method=submitter?.hasAttribute('formmethod')?submitter.formMethod:form.method;
        if(!local(action)||!mutates(method)){form.querySelectorAll('input[name="app_csrf"]').forEach(input=>input.remove());return;}
        let input=form.querySelector('input[name="app_csrf"]');
        if(!input){input=document.createElement('input');input.type='hidden';input.name='app_csrf';form.append(input);}input.value=token;
    },true);
})();
