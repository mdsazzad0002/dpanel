(function () {
    var script = document.currentScript;
    var channel = script.getAttribute('data-channel');
    if (!channel) {
        console.error('Chat widget: missing data-channel attribute.');
        return;
    }

    var origin = new URL(script.src).origin;
    var endpoint = origin + '/widget/chat/' + channel;
    var mediaEndpoint = endpoint + '/media';
    var storageKey = 'dpanel_chat_widget_token_' + channel;

    function getToken() {
        try { return localStorage.getItem(storageKey); } catch (e) { return null; }
    }
    function setToken(token) {
        try { localStorage.setItem(storageKey, token); } catch (e) {}
    }

    var ICONS = {
        chat: '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>',
        close: '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        image: '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="1.8"/><path d="m21 15-5-5-9 9"/></svg>',
        mic: '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10.5a7 7 0 0 0 14 0M12 18.5v3"/></svg>',
        send: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 18-8-8 18-2.5-7.5L3 11z"/></svg>',
        trash: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0-1 14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1L4 6"/></svg>',
        check: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
    };

    var style = document.createElement('style');
    style.textContent = [
        '#dpanel-chat-bubble{position:fixed;bottom:20px;right:20px;width:58px;height:58px;border-radius:50%;background:linear-gradient(135deg,#4f46e5,#2563eb);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 8px 24px rgba(37,99,235,.4);z-index:2147483000;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;transition:transform .15s ease,box-shadow .15s ease;border:0;}',
        '#dpanel-chat-bubble:hover{transform:translateY(-2px) scale(1.04);box-shadow:0 10px 28px rgba(37,99,235,.5);}',
        '#dpanel-chat-bubble svg{transition:opacity .15s ease,transform .15s ease;}',
        '#dpanel-chat-bubble .dpanel-icon-close{position:absolute;opacity:0;transform:rotate(-45deg) scale(.6);}',
        '#dpanel-chat-bubble.open .dpanel-icon-chat{opacity:0;transform:rotate(45deg) scale(.6);}',
        '#dpanel-chat-bubble.open .dpanel-icon-close{opacity:1;transform:rotate(0) scale(1);position:static;}',
        '#dpanel-chat-bubble:not(.open) .dpanel-icon-close{position:absolute;}',
        '#dpanel-chat-badge{position:absolute;top:-2px;right:-2px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;background:#ef4444;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 2px #fff;}',
        '#dpanel-chat-panel{position:fixed;bottom:90px;right:20px;width:340px;max-width:92vw;height:480px;max-height:75vh;background:#fff;border-radius:18px;box-shadow:0 20px 60px rgba(15,23,42,.25);display:none;flex-direction:column;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;z-index:2147483000;opacity:0;transform:translateY(12px) scale(.98);transition:opacity .18s ease,transform .18s ease;}',
        '#dpanel-chat-panel.open{display:flex;}',
        '#dpanel-chat-panel.visible{opacity:1;transform:translateY(0) scale(1);}',
        '#dpanel-chat-header{background:linear-gradient(135deg,#4f46e5,#2563eb);color:#fff;padding:16px 16px;font-size:14px;font-weight:600;display:flex;align-items:center;gap:10px;flex-shrink:0;}',
        '#dpanel-chat-header-avatar{width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;flex-shrink:0;}',
        '#dpanel-chat-header-text{display:flex;flex-direction:column;gap:1px;}',
        '#dpanel-chat-header-sub{font-size:11px;font-weight:400;opacity:.85;display:flex;align-items:center;gap:5px;}',
        '#dpanel-chat-header-sub .dpanel-dot{width:6px;height:6px;border-radius:50%;background:#4ade80;box-shadow:0 0 0 2px rgba(74,222,128,.35);}',
        '#dpanel-chat-messages{flex:1;overflow-y:auto;padding:14px 12px;font-size:13.5px;background:#f5f7fb;display:flex;flex-direction:column;gap:10px;}',
        '.dpanel-chat-row{display:flex;max-width:88%;animation:dpanel-fade-in .18s ease;}',
        '.dpanel-chat-row.user{align-self:flex-end;flex-direction:row-reverse;}',
        '.dpanel-chat-row.assistant{align-self:flex-start;}',
        '@keyframes dpanel-fade-in{from{opacity:0;transform:translateY(4px);}to{opacity:1;transform:translateY(0);}}',
        '.dpanel-chat-msg{padding:9px 12px;border-radius:14px;line-height:1.45;white-space:pre-wrap;word-wrap:break-word;box-shadow:0 1px 2px rgba(15,23,42,.06);}',
        '.dpanel-chat-row.user .dpanel-chat-msg{background:linear-gradient(135deg,#4f46e5,#2563eb);color:#fff;border-bottom-right-radius:4px;}',
        '.dpanel-chat-row.assistant .dpanel-chat-msg{background:#fff;color:#0f172a;border-bottom-left-radius:4px;}',
        '.dpanel-chat-msg img.dpanel-chat-image{display:block;max-width:190px;max-height:190px;border-radius:10px;margin-bottom:4px;cursor:zoom-in;object-fit:cover;}',
        '.dpanel-chat-msg audio.dpanel-chat-audio{display:block;width:210px;max-width:100%;height:34px;}',
        '.dpanel-chat-caption{margin-top:4px;}',
        '.dpanel-typing{display:flex;align-items:center;gap:4px;padding:11px 14px;background:#fff;border-radius:14px;border-bottom-left-radius:4px;box-shadow:0 1px 2px rgba(15,23,42,.06);width:fit-content;}',
        '.dpanel-typing span{width:6px;height:6px;border-radius:50%;background:#94a3b8;animation:dpanel-bounce 1.2s infinite ease-in-out;}',
        '.dpanel-typing span:nth-child(2){animation-delay:.15s;}',
        '.dpanel-typing span:nth-child(3){animation-delay:.3s;}',
        '@keyframes dpanel-bounce{0%,60%,100%{transform:translateY(0);opacity:.5;}30%{transform:translateY(-4px);opacity:1;}}',
        '#dpanel-chat-composer{border-top:1px solid #e7eaf3;background:#fff;flex-shrink:0;}',
        '#dpanel-chat-form{display:flex;align-items:center;gap:2px;padding:8px;}',
        '#dpanel-chat-input{flex:1;border:0;background:#f1f3f9;border-radius:20px;padding:9px 14px;font-size:13.5px;outline:none;min-width:0;color:#0f172a;}',
        '#dpanel-chat-input::placeholder{color:#94a3b8;}',
        '.dpanel-icon-btn{border:0;background:none;color:#64748b;width:34px;height:34px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .12s ease,color .12s ease;}',
        '.dpanel-icon-btn:hover:not(:disabled){background:#eef1f8;color:#334155;}',
        '.dpanel-icon-btn:disabled{opacity:.4;cursor:default;}',
        '#dpanel-chat-send{background:linear-gradient(135deg,#4f46e5,#2563eb);color:#fff;margin-left:2px;}',
        '#dpanel-chat-send:hover:not(:disabled){filter:brightness(1.08);background:linear-gradient(135deg,#4f46e5,#2563eb);color:#fff;}',
        '#dpanel-chat-send:disabled{opacity:.4;}',
        '#dpanel-chat-recording{display:none;align-items:center;gap:10px;padding:8px 14px;}',
        '#dpanel-chat-recording.active{display:flex;}',
        '#dpanel-chat-form.hidden{display:none;}',
        '.dpanel-rec-dot{width:10px;height:10px;border-radius:50%;background:#ef4444;flex-shrink:0;animation:dpanel-pulse 1s infinite;}',
        '@keyframes dpanel-pulse{0%,100%{opacity:1;}50%{opacity:.35;}}',
        '.dpanel-rec-bars{display:flex;align-items:center;gap:2px;height:18px;flex:1;}',
        '.dpanel-rec-bars span{width:3px;border-radius:2px;background:#4f46e5;animation:dpanel-bar 1s infinite ease-in-out;}',
        '.dpanel-rec-bars span:nth-child(1){height:40%;animation-delay:0s;}',
        '.dpanel-rec-bars span:nth-child(2){height:100%;animation-delay:.1s;}',
        '.dpanel-rec-bars span:nth-child(3){height:65%;animation-delay:.2s;}',
        '.dpanel-rec-bars span:nth-child(4){height:90%;animation-delay:.3s;}',
        '.dpanel-rec-bars span:nth-child(5){height:50%;animation-delay:.4s;}',
        '@keyframes dpanel-bar{0%,100%{transform:scaleY(.4);}50%{transform:scaleY(1);}}',
        '#dpanel-rec-timer{font-variant-numeric:tabular-nums;font-size:12.5px;color:#475569;font-weight:600;min-width:34px;}',
        '.dpanel-rec-action{border:0;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;}',
        '.dpanel-rec-cancel{background:#f1f3f9;color:#64748b;}',
        '.dpanel-rec-cancel:hover{background:#e7eaf3;}',
        '.dpanel-rec-stop{background:linear-gradient(135deg,#4f46e5,#2563eb);color:#fff;}',
        '.dpanel-rec-stop:hover{filter:brightness(1.08);}',
        '#dpanel-chat-lightbox{position:fixed;inset:0;background:rgba(15,23,42,.85);z-index:2147483001;display:none;align-items:center;justify-content:center;padding:24px;cursor:zoom-out;}',
        '#dpanel-chat-lightbox.open{display:flex;}',
        '#dpanel-chat-lightbox img{max-width:100%;max-height:100%;border-radius:8px;box-shadow:0 20px 50px rgba(0,0,0,.4);}',
        '@media (max-width:420px){#dpanel-chat-panel{right:10px;left:10px;width:auto;bottom:82px;}#dpanel-chat-bubble{right:16px;bottom:16px;}}',
    ].join('');
    document.head.appendChild(style);

    var bubble = document.createElement('button');
    bubble.id = 'dpanel-chat-bubble';
    bubble.type = 'button';
    bubble.setAttribute('aria-label', 'Open chat');
    bubble.innerHTML =
        '<span class="dpanel-icon-chat">' + ICONS.chat + '</span>' +
        '<span class="dpanel-icon-close">' + ICONS.close + '</span>' +
        '<span id="dpanel-chat-badge" style="display:none">1</span>';

    var panel = document.createElement('div');
    panel.id = 'dpanel-chat-panel';
    panel.innerHTML =
        '<div id="dpanel-chat-header">' +
        '<div id="dpanel-chat-header-avatar">' + ICONS.chat + '</div>' +
        '<div id="dpanel-chat-header-text">' +
        '<div>Chat with us</div>' +
        '<div id="dpanel-chat-header-sub"><span class="dpanel-dot"></span>Usually replies instantly</div>' +
        '</div>' +
        '</div>' +
        '<div id="dpanel-chat-messages"></div>' +
        '<div id="dpanel-chat-composer">' +
        '<form id="dpanel-chat-form">' +
        '<button id="dpanel-chat-attach" type="button" class="dpanel-icon-btn" title="Send an image">' + ICONS.image + '</button>' +
        '<button id="dpanel-chat-mic" type="button" class="dpanel-icon-btn" title="Send a voice message">' + ICONS.mic + '</button>' +
        '<input id="dpanel-chat-file" type="file" accept="image/*" style="display:none" />' +
        '<input id="dpanel-chat-input" type="text" placeholder="Type a message…" autocomplete="off" />' +
        '<button id="dpanel-chat-send" type="submit" class="dpanel-icon-btn" title="Send">' + ICONS.send + '</button>' +
        '</form>' +
        '<div id="dpanel-chat-recording">' +
        '<span class="dpanel-rec-dot"></span>' +
        '<div class="dpanel-rec-bars"><span></span><span></span><span></span><span></span><span></span></div>' +
        '<span id="dpanel-rec-timer">0:00</span>' +
        '<button type="button" class="dpanel-rec-action dpanel-rec-cancel" id="dpanel-rec-cancel" title="Cancel">' + ICONS.trash + '</button>' +
        '<button type="button" class="dpanel-rec-action dpanel-rec-stop" id="dpanel-rec-stop" title="Send">' + ICONS.check + '</button>' +
        '</div>' +
        '</div>';

    var lightbox = document.createElement('div');
    lightbox.id = 'dpanel-chat-lightbox';
    lightbox.innerHTML = '<img alt="" />';

    document.body.appendChild(bubble);
    document.body.appendChild(panel);
    document.body.appendChild(lightbox);

    var messagesEl = panel.querySelector('#dpanel-chat-messages');
    var form = panel.querySelector('#dpanel-chat-form');
    var input = panel.querySelector('#dpanel-chat-input');
    var sendBtn = panel.querySelector('#dpanel-chat-send');
    var attachBtn = panel.querySelector('#dpanel-chat-attach');
    var micBtn = panel.querySelector('#dpanel-chat-mic');
    var fileInput = panel.querySelector('#dpanel-chat-file');
    var recordingBar = panel.querySelector('#dpanel-chat-recording');
    var recTimerEl = panel.querySelector('#dpanel-rec-timer');
    var recCancelBtn = panel.querySelector('#dpanel-rec-cancel');
    var recStopBtn = panel.querySelector('#dpanel-rec-stop');
    var lightboxImg = lightbox.querySelector('img');
    var badge = bubble.querySelector('#dpanel-chat-badge');

    var unreadCount = 0;
    var isOpen = false;

    function updateBadge() {
        if (unreadCount > 0 && !isOpen) {
            badge.style.display = 'flex';
            badge.textContent = unreadCount > 9 ? '9+' : String(unreadCount);
        } else {
            badge.style.display = 'none';
        }
    }

    function openLightbox(src) {
        lightboxImg.src = src;
        lightbox.classList.add('open');
    }
    lightbox.addEventListener('click', function () {
        lightbox.classList.remove('open');
    });

    function scrollToBottom() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    /**
     * kind: 'text' | 'image' | 'audio'. For image/audio, `media` is an
     * object URL for local (just-sent) previews.
     */
    function addMessage(role, kind, text, media) {
        var row = document.createElement('div');
        row.className = 'dpanel-chat-row ' + role;

        var bubbleEl = document.createElement('div');
        bubbleEl.className = 'dpanel-chat-msg';

        if (kind === 'image' && media) {
            var img = document.createElement('img');
            img.className = 'dpanel-chat-image';
            img.src = media;
            img.alt = 'Sent image';
            img.addEventListener('click', function () { openLightbox(media); });
            bubbleEl.appendChild(img);
        }

        if (kind === 'audio' && media) {
            var audio = document.createElement('audio');
            audio.className = 'dpanel-chat-audio';
            audio.src = media;
            audio.controls = true;
            bubbleEl.appendChild(audio);
        }

        if (text) {
            var textEl = document.createElement('div');
            if (kind === 'image' || kind === 'audio') textEl.className = 'dpanel-chat-caption';
            textEl.textContent = text;
            bubbleEl.appendChild(textEl);
        }

        row.appendChild(bubbleEl);
        messagesEl.appendChild(row);
        scrollToBottom();

        if (role === 'assistant' && !isOpen) {
            unreadCount++;
            updateBadge();
        }
    }

    var typingRow = null;
    function showTyping() {
        hideTyping();
        typingRow = document.createElement('div');
        typingRow.className = 'dpanel-chat-row assistant';
        typingRow.innerHTML = '<div class="dpanel-typing"><span></span><span></span><span></span></div>';
        messagesEl.appendChild(typingRow);
        scrollToBottom();
    }
    function hideTyping() {
        if (typingRow && typingRow.parentNode) typingRow.parentNode.removeChild(typingRow);
        typingRow = null;
    }

    bubble.addEventListener('click', function () {
        isOpen = !isOpen;
        bubble.classList.toggle('open', isOpen);
        panel.classList.toggle('open', isOpen);
        if (isOpen) {
            requestAnimationFrame(function () { panel.classList.add('visible'); });
            unreadCount = 0;
            updateBadge();
            input.focus();
        } else {
            panel.classList.remove('visible');
        }
    });

    function setControlsDisabled(disabled) {
        sendBtn.disabled = disabled;
        attachBtn.disabled = disabled;
        micBtn.disabled = disabled;
    }

    function handleReply(promise) {
        showTyping();
        return promise
            .then(function (res) { return res.json(); })
            .then(function (data) {
                hideTyping();
                if (data.contact_token) setToken(data.contact_token);
                if (data.reply) addMessage('assistant', 'text', data.reply);
            })
            .catch(function () {
                hideTyping();
                addMessage('assistant', 'text', 'Sorry, something went wrong. Please try again.');
            });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = input.value.trim();
        if (!text) return;

        addMessage('user', 'text', text);
        input.value = '';
        setControlsDisabled(true);

        handleReply(fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ contact_token: getToken(), message: text }),
        })).finally(function () {
            setControlsDisabled(false);
        });
    });

    function sendMedia(blob, type, previewUrl, fileName) {
        addMessage('user', type, null, previewUrl);
        setControlsDisabled(true);

        var body = new FormData();
        body.append('type', type);
        body.append('file', blob, fileName);
        if (getToken()) body.append('contact_token', getToken());

        handleReply(fetch(mediaEndpoint, { method: 'POST', body: body })).finally(function () {
            setControlsDisabled(false);
        });
    }

    // --- Image upload ---
    attachBtn.addEventListener('click', function () {
        fileInput.click();
    });

    fileInput.addEventListener('change', function () {
        var file = fileInput.files && fileInput.files[0];
        fileInput.value = '';
        if (!file) return;
        sendMedia(file, 'image', URL.createObjectURL(file), file.name || 'photo.jpg');
    });

    // --- Voice recording ---
    var mediaRecorder = null;
    var recordedChunks = [];
    var recTimerInterval = null;
    var recStartedAt = 0;
    var recCancelled = false;

    function formatDuration(ms) {
        var totalSeconds = Math.floor(ms / 1000);
        var minutes = Math.floor(totalSeconds / 60);
        var seconds = totalSeconds % 60;
        return minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
    }

    function showRecordingUi(show) {
        recordingBar.classList.toggle('active', show);
        form.classList.toggle('hidden', show);
    }

    function startRecording() {
        if (!navigator.mediaDevices || !window.MediaRecorder) {
            addMessage('assistant', 'text', 'Voice messages are not supported in this browser.');
            return;
        }

        navigator.mediaDevices.getUserMedia({ audio: true })
            .then(function (stream) {
                recordedChunks = [];
                recCancelled = false;
                mediaRecorder = new MediaRecorder(stream);
                mediaRecorder.addEventListener('dataavailable', function (e) {
                    if (e.data && e.data.size > 0) recordedChunks.push(e.data);
                });
                mediaRecorder.addEventListener('stop', function () {
                    stream.getTracks().forEach(function (track) { track.stop(); });
                    clearInterval(recTimerInterval);
                    showRecordingUi(false);
                    if (recCancelled || recordedChunks.length === 0) return;
                    var blob = new Blob(recordedChunks, { type: 'audio/webm' });
                    sendMedia(blob, 'audio', URL.createObjectURL(blob), 'voice-message.webm');
                });
                mediaRecorder.start();
                recStartedAt = Date.now();
                recTimerEl.textContent = '0:00';
                showRecordingUi(true);
                recTimerInterval = setInterval(function () {
                    recTimerEl.textContent = formatDuration(Date.now() - recStartedAt);
                }, 250);
            })
            .catch(function () {
                addMessage('assistant', 'text', 'Microphone access was denied.');
            });
    }

    function stopRecording(cancel) {
        recCancelled = !!cancel;
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
        }
    }

    micBtn.addEventListener('click', startRecording);
    recStopBtn.addEventListener('click', function () { stopRecording(false); });
    recCancelBtn.addEventListener('click', function () { stopRecording(true); });
})();
