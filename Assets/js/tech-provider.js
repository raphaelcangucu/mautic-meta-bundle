(() => {
    'use strict';
    const form = document.getElementById('meta-provider-signup');
    if (!form || form.dataset.initialized) return;
    form.dataset.initialized = '1';
    const button = document.getElementById('meta-provider-launch');
    const result = document.getElementById('meta-provider-result');
    let active = false, code = null, ids = null, submitted = false;
    const finish = () => {
        if (!active || submitted || !code || !ids) return;
        submitted = true; active = false;
        form.elements.code.value = code;
        form.elements.waba_id.value = ids.waba_id;
        form.elements.phone_id.value = ids.phone_number_id;
        result.textContent = 'Validando autorização no servidor…';
        form.submit();
    };
    window.addEventListener('message', event => {
        if (!active || !['https://www.facebook.com', 'https://web.facebook.com'].includes(event.origin)) return;
        let data; try { data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data; } catch (_) { return; }
        if (!data || data.type !== 'WA_EMBEDDED_SIGNUP') return;
        if (data.event === 'FINISH') {
            if (!/^\d{5,32}$/.test(data.data?.waba_id) || !/^\d{5,32}$/.test(data.data?.phone_number_id)) return;
            ids = data.data; finish();
        } else if (['CANCEL', 'ERROR'].includes(data.event)) {
            active = false; result.textContent = 'Autorização interrompida. Atualize a página para iniciar novamente.';
        }
    });
    const ready = () => {
        FB.init({appId: form.dataset.app, version: form.dataset.version, cookie: false, xfbml: false});
        button.disabled = false; result.textContent = 'Pronto para autorizar na Meta.';
    };
    button.addEventListener('click', () => {
        if (!form.reportValidity() || active || submitted) return;
        active = true; button.disabled = true;
        FB.login(response => {
            if (response.authResponse?.code) { code = response.authResponse.code; finish(); }
            else { active = false; result.textContent = 'Sem autorização. Atualize a página para tentar novamente.'; }
        }, {config_id: form.dataset.config, response_type: 'code', override_default_response_type: true, extras: {sessionInfoVersion: '3'}});
    });
    if (window.FB) ready();
    else { window.fbAsyncInit = ready; const sdk = document.createElement('script'); sdk.src = 'https://connect.facebook.net/pt_BR/sdk.js'; sdk.async = true; sdk.onerror = () => { result.textContent = 'SDK indisponível. Nenhuma autorização foi enviada.'; }; document.head.appendChild(sdk); }
})();
