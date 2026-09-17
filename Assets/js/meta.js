(function () {
    'use strict';
    var translations = {}, locale = 'en-US';
    function t(key, parameters) { var text = translations[key] || key; Object.keys(parameters || {}).forEach(function (name) { text = text.split('%'+name+'%').join(String(parameters[name])); }); return text; }

    function boot() {
        var root = document.querySelector('.meta-ui');
        if (!root || root.dataset.ready) return;
        root.dataset.ready = '1';
        try { translations = JSON.parse(root.dataset.translations || '{}'); } catch (e) { translations = {}; }
        locale = (root.dataset.locale || 'en_US').replace(/_/g, '-');
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
        var actionMenus = Array.prototype.slice.call(root.querySelectorAll('[data-meta-action-menu]'));
        actionMenus.forEach(function (menu) {
            menu.addEventListener('toggle', function () {
                if (!menu.open) return;
                actionMenus.forEach(function (other) { if (other !== menu) other.open = false; });
            });
        });
        document.addEventListener('click', function (event) {
            actionMenus.forEach(function (menu) { if (menu.open && !menu.contains(event.target)) menu.open = false; });
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') actionMenus.forEach(function (menu) { menu.open = false; });
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
        function numberedOptions(text) {
            var options = [], kept = [];
            String(text || '').split(/\n/).forEach(function (line) {
                if (/responda\s+(apenas\s+)?com\s+um\s+número|pode\s+responder\s+só\s+com\s+o\s+número/i.test(line)) return;
                var match = line.match(/^\s*(?:(?:[1-9]|10)[\.\)\:]|[1-9]\s*[—\-]|[0-9]\uFE0F?\u20E3)\s+(.+?)\s*$/);
                if (match) { options.push(match[1].trim()); return; }
                kept.push(line);
            });
            return { text: kept.join('\n').replace(/\n{3,}/g, '\n\n').trim(), options: options };
        }
        function samplesMissing(part) {
            var needed = tokens((part && part.text) || '');
            if (!needed.length) return false;
            var values = part && part.example && part.example.body_text && part.example.body_text[0];
            return needed.some(function (token) {
                var index = Number(token.replace(/\D/g, '')) - 1;
                return !values || !String(values[index] || '').trim();
            });
        }
        function tokens(text) { return Array.from(new Set((text.match(/\{\{\d+\}\}/g) || []))).sort(function (a,b) { return Number(a.replace(/\D/g,''))-Number(b.replace(/\D/g,'')); }); }
        function drawExamples() {
            var old = {}; examples.querySelectorAll('input').forEach(function (input) { old[input.dataset.token] = input.value; }); examples.textContent = '';
            var part = components.find(function (c) { return c.type === 'BODY'; });
            tokens(body.value).forEach(function (token) {
                var index = Number(token.replace(/\D/g,''))-1, label = document.createElement('label'), input = document.createElement('input');
                label.textContent = t("mautic.meta.ui.example_for_5509f0") + token; input.className = 'form-control'; input.dataset.token = token;
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
            } catch (e) { visual.hidden = true; fail(t("mautic.meta.ui.invalid_json_correct_the_configuration_before_submitting_your_con_f0e78c")); return false; }
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
        form.addEventListener('submit', function (event) {
            if (!fromJson()) { event.preventDefault(); return; }
            var part = components.find(function (c) { return c.type === 'BODY'; });
            var parsed = numberedOptions(part && part.text || '');
            if (parsed.options.length > 3) {
                fail(t("mautic.meta.ui.whatsapp_allows_at_most_3_quick_reply_buttons_c4e1a2"));
                event.preventDefault();
                return;
            }
            if (samplesMissing(part)) {
                fail(t("mautic.meta.ui.add_a_sample_value_for_each_variable_before_submitting_to_meta_9b2d70"));
                event.preventDefault();
                return;
            }
            if (!window.confirm(t("mautic.meta.ui.submit_this_content_to_meta_for_review_this_change_affects_the_te_bbdd72"))) event.preventDefault();
        });
        if (fromJson() && !visual.hidden) form.querySelector('[data-template-advanced]').open = false;
    }
    if (window.Mautic) window.Mautic.metaOnLoad = boot;
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
}());
