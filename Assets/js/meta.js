(function () {
    'use strict';
    function boot() {
        var root = document.querySelector('.meta-ui');
        if (!root || root.dataset.ready) return;
        root.dataset.ready = '1';
        // Keep list context in the browser without accepting arbitrary return URLs.
        var routes = ['/s/meta/connections', '/s/meta/whatsapp/templates', '/s/meta/identities', '/s/meta/operations'];
        routes.forEach(function (route) {
            try {
                if (location.pathname === route || (route.endsWith('identities') && /^\/s\/meta\/identities\/\d+$/.test(location.pathname))) sessionStorage.setItem('meta-list:' + route, location.pathname + location.search);
                root.querySelectorAll('[data-meta-back]').forEach(function (link) {
                    var url = new URL(link.href, location.href), saved = sessionStorage.getItem('meta-list:' + route);
                    if (url.pathname === route && saved && (saved.split('?')[0] === route || (route.endsWith('identities') && /^\/s\/meta\/identities\/\d+$/.test(saved.split('?')[0])))) link.href = saved;
                });
            } catch (e) { /* Navigation remains usable without browser storage. */ }
        });
        var assetForm = root.querySelector('[data-meta-asset]');
        if (assetForm) {
            var type = assetForm.querySelector('[name="meta_asset[type]"]');
            function channelFields() { assetForm.querySelectorAll('[data-asset-only]').forEach(function (section) { section.hidden = section.dataset.assetOnly !== type.value; }); }
            if (type) { type.addEventListener('change', channelFields); if (window.mQuery) window.mQuery(type).on('change.metaUi', channelFields); channelFields(); }
        }
        var form = root.querySelector('[data-meta-template]');
        if (!form) return;
        var json = form.querySelector('[name="whats_app_template[components_json]"]'), body = form.querySelector('#meta-template-body'), footer = form.querySelector('#meta-template-footer'), examples = form.querySelector('#meta-template-examples'), preview = form.querySelector('#meta-template-preview'), error = form.querySelector('#meta-template-error'), visual = form.querySelector('[data-template-visual]');
        var components = [], updating = false;
        function fail(message) { error.textContent = message; error.hidden = !message; }
        function tokens(text) { return Array.from(new Set((text.match(/\{\{\d+\}\}/g) || []))).sort(function (a,b) { return Number(a.replace(/\D/g,''))-Number(b.replace(/\D/g,'')); }); }
        function drawExamples() {
            var old = {}; examples.querySelectorAll('input').forEach(function (input) { old[input.dataset.token] = input.value; }); examples.textContent = '';
            var part = components.find(function (c) { return c.type === 'BODY'; });
            tokens(body.value).forEach(function (token) {
                var index = Number(token.replace(/\D/g,''))-1, label = document.createElement('label'), input = document.createElement('input');
                label.textContent = 'Exemplo para ' + token; input.className = 'form-control'; input.dataset.token = token;
                input.value = old[token] !== undefined ? old[token] : (part && part.example && part.example.body_text && part.example.body_text[0] && part.example.body_text[0][index]) || '';
                label.appendChild(input); examples.appendChild(label); input.addEventListener('input', toJson);
            });
        }
        function display() {
            var values = {}; examples.querySelectorAll('input').forEach(function (input) { values[input.dataset.token] = input.value; });
            preview.textContent = components.map(function (c) {
                if (c.text) return String(c.text).replace(/\{\{\d+\}\}/g, function (token) { return values[token] || token; });
                if (c.type === 'BUTTONS') return (c.buttons || []).map(function (b) { return '▸ '+(b.text || b.type); }).join('\n');
                return '['+(c.format || c.type)+']';
            }).join('\n\n');
        }
        function fromJson() {
            if (updating) return;
            try {
                var next = JSON.parse(json.value);
                if (!Array.isArray(next) || next.some(function (c) { return !c || typeof c !== 'object' || Array.isArray(c); })) throw new Error();
                components = next; var b = components.filter(function (c) { return c.type === 'BODY'; }), f = components.filter(function (c) { return c.type === 'FOOTER'; });
                // Named variables and nonstandard bodies remain editable in JSON, without lossy conversion.
                var supported = b.length <= 1 && f.length <= 1 && !components.some(function (c) { return c.parameter_format === 'NAMED'; }) && !(b[0] && /\{\{[^\d}][^}]*\}\}/.test(b[0].text || ''));
                visual.hidden = !supported; body.value = b[0] && b[0].text || ''; footer.value = f[0] && f[0].text || '';
                examples.textContent = ''; drawExamples(); display(); fail('');
                if (!supported) form.querySelector('[data-template-advanced]').open = true;
                return true;
            } catch (e) { visual.hidden = true; fail('JSON inválido. Corrija a configuração antes de enviar; o conteúdo não foi descartado.'); return false; }
        }
        function toJson() {
            if (visual.hidden) return;
            var b = components.find(function (c) { return c.type === 'BODY'; });
            if (!b) { b = {type:'BODY',text:''}; components.push(b); } b.text = body.value;
            var values = []; examples.querySelectorAll('input').forEach(function (input) { values[Number(input.dataset.token.replace(/\D/g,''))-1] = input.value; });
            if (values.length) b.example = Object.assign({}, b.example || {}, {body_text:[values]}); else if (b.example && b.example.body_text) { delete b.example.body_text; }
            var f = components.find(function (c) { return c.type === 'FOOTER'; });
            if (footer.value) { if (!f) { f = {type:'FOOTER'}; components.push(f); } f.text = footer.value; }
            else components = components.filter(function (c) { return c.type !== 'FOOTER'; });
            updating = true; json.value = JSON.stringify(components, null, 2); updating = false; display();
        }
        body.addEventListener('input', function () { drawExamples(); toJson(); }); footer.addEventListener('input', toJson); json.addEventListener('input', fromJson);
        form.addEventListener('submit', function (event) { if (!fromJson() || !window.confirm('Enviar este conteúdo à Meta para análise? A alteração afeta o modelo desta conta.')) event.preventDefault(); });
        if (fromJson() && !visual.hidden) form.querySelector('[data-template-advanced]').open = false;
    }
    if (window.Mautic) window.Mautic.metaOnLoad = boot;
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
}());
