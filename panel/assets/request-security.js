(() => {
    'use strict';
    const token=document.querySelector('meta[name="app-csrf"]')?.content;
    if(!token)return;
    const project=document.querySelector('meta[name="project-context"]')?.content||'';
    const local=url=>{try{return new URL(url,location.href).origin===location.origin;}catch{return false;}};
    const mutates=method=>!['GET','HEAD','OPTIONS'].includes(String(method||'GET').toUpperCase());
    const originalFetch=window.fetch;
    window.fetch=function(resource,init={}) {
        const request=resource instanceof Request?resource:null;
        if(local(request?.url||resource)) {
            const headers=new Headers(request?.headers);new Headers(init.headers).forEach((v,k)=>headers.set(k,v));
            if(mutates(init.method||request?.method))headers.set('X-CSRF-Token',token);headers.set('X-Project-Context',project);init={...init,headers};
        }
        return originalFetch.call(this,resource,init);
    };
    const open=XMLHttpRequest.prototype.open,send=XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open=function(method,url,...args){this._appLocal=local(url);this._appCsrf=this._appLocal&&mutates(method);return open.call(this,method,url,...args);};
    XMLHttpRequest.prototype.send=function(...args){if(this._appCsrf)this.setRequestHeader('X-CSRF-Token',token);if(this._appLocal)this.setRequestHeader('X-Project-Context',project);return send.apply(this,args);};
    document.addEventListener('click',event=>{
        const a=event.target.closest?.('a[href]');if(!a||!project||!local(a.href))return;
        const url=new URL(a.href,location.href);if(!url.pathname.startsWith('/panel/')||/\/(workspace|support|backups)\.php$/.test(url.pathname)||a.getAttribute('href').startsWith('#'))return;
        url.searchParams.set('_project_context',project);a.href=url.href;
    },true);
    document.addEventListener('submit',event=>{
        const form=event.target;
        if(!(form instanceof HTMLFormElement))return;
        const submitter=event.submitter;
        const action=submitter?.hasAttribute('formaction')?submitter.formAction:form.action;
        const method=submitter?.hasAttribute('formmethod')?submitter.formMethod:form.method;
        if(!local(action)){form.querySelectorAll('input[name="app_csrf"],input[name="_project_context"]').forEach(input=>input.remove());return;}
        let context=form.querySelector('input[name="_project_context"]');if(!context){context=document.createElement('input');context.type='hidden';context.name='_project_context';form.append(context);}context.value=project;
        if(!mutates(method)){form.querySelectorAll('input[name="app_csrf"]').forEach(input=>input.remove());return;}
        let input=form.querySelector('input[name="app_csrf"]');
        if(!input){input=document.createElement('input');input.type='hidden';input.name='app_csrf';form.append(input);}input.value=token;

    },true);
})();
