(function () {
    var script = document.currentScript;
    var channel = script.getAttribute('data-channel');
    if (!channel) {
        console.error('Chat widget: missing data-channel attribute.');
        return;
    }

    var origin = new URL(script.src).origin;
    var endpoint = origin + '/widget/chat/' + channel;
    var storageKey = 'dpanel_chat_widget_token_' + channel;

    function getToken() {
        try { return localStorage.getItem(storageKey); } catch (e) { return null; }
    }
    function setToken(token) {
        try { localStorage.setItem(storageKey, token); } catch (e) {}
    }

    var style = document.createElement('style');
    style.textContent = [
        '#dpanel-chat-bubble{position:fixed;bottom:20px;right:20px;width:56px;height:56px;border-radius:50%;background:#2563eb;color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.2);z-index:2147483000;font-family:system-ui,sans-serif;font-size:24px;}',
        '#dpanel-chat-panel{position:fixed;bottom:88px;right:20px;width:320px;max-width:90vw;height:440px;max-height:70vh;background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.25);display:none;flex-direction:column;overflow:hidden;font-family:system-ui,sans-serif;z-index:2147483000;}',
        '#dpanel-chat-panel.open{display:flex;}',
        '#dpanel-chat-header{background:#2563eb;color:#fff;padding:12px 14px;font-size:14px;font-weight:600;}',
        '#dpanel-chat-messages{flex:1;overflow-y:auto;padding:10px;font-size:13px;background:#f8fafc;}',
        '.dpanel-chat-msg{margin-bottom:8px;max-width:85%;padding:8px 10px;border-radius:10px;line-height:1.4;white-space:pre-wrap;word-wrap:break-word;}',
        '.dpanel-chat-msg.user{margin-left:auto;background:#2563eb;color:#fff;border-bottom-right-radius:2px;}',
        '.dpanel-chat-msg.assistant{margin-right:auto;background:#e2e8f0;color:#0f172a;border-bottom-left-radius:2px;}',
        '#dpanel-chat-form{display:flex;border-top:1px solid #e2e8f0;}',
        '#dpanel-chat-input{flex:1;border:0;padding:10px;font-size:13px;outline:none;}',
        '#dpanel-chat-send{border:0;background:#2563eb;color:#fff;padding:0 14px;cursor:pointer;font-size:13px;}',
        '#dpanel-chat-send:disabled{opacity:.5;cursor:default;}',
    ].join('');
    document.head.appendChild(style);

    var bubble = document.createElement('div');
    bubble.id = 'dpanel-chat-bubble';
    bubble.textContent = '💬';

    var panel = document.createElement('div');
    panel.id = 'dpanel-chat-panel';
    panel.innerHTML =
        '<div id="dpanel-chat-header">Chat with us</div>' +
        '<div id="dpanel-chat-messages"></div>' +
        '<form id="dpanel-chat-form">' +
        '<input id="dpanel-chat-input" type="text" placeholder="Type a message…" autocomplete="off" />' +
        '<button id="dpanel-chat-send" type="submit">Send</button>' +
        '</form>';

    document.body.appendChild(bubble);
    document.body.appendChild(panel);

    var messagesEl = panel.querySelector('#dpanel-chat-messages');
    var form = panel.querySelector('#dpanel-chat-form');
    var input = panel.querySelector('#dpanel-chat-input');
    var sendBtn = panel.querySelector('#dpanel-chat-send');

    function addMessage(role, text) {
        var el = document.createElement('div');
        el.className = 'dpanel-chat-msg ' + role;
        el.textContent = text;
        messagesEl.appendChild(el);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    bubble.addEventListener('click', function () {
        panel.classList.toggle('open');
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = input.value.trim();
        if (!text) return;

        addMessage('user', text);
        input.value = '';
        sendBtn.disabled = true;

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ contact_token: getToken(), message: text }),
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.contact_token) setToken(data.contact_token);
                if (data.reply) addMessage('assistant', data.reply);
            })
            .catch(function () {
                addMessage('assistant', 'Sorry, something went wrong. Please try again.');
            })
            .finally(function () {
                sendBtn.disabled = false;
            });
    });
})();
