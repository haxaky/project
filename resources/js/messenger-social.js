import { socialRequest } from './social';

function element(tag, className = '', text = '') {
    const node = document.createElement(tag);
    node.className = className;
    node.textContent = text;
    return node;
}

export function initializeGroups() {
    const config = window.messengerSocial;
    let currentRoute = location.pathname + location.search;
    function pushRoute(url) {
        history.pushState({}, '', url);
        currentRoute = location.pathname + location.search;
    }
    const list = document.querySelector('.listOfGroups');
    const messages = document.querySelector('.messages');
    const container = document.querySelector('.messages-container');
    const form = document.querySelector('#message-form');
    const errorBox = document.querySelector('#group-chat-error');
    const dialog = document.querySelector('#group-dialog');
    const personalAccept = form.querySelector('input[type=file]').accept;
    const groupAccept = '.jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.zip,.doc,.docx,.xls,.xlsx,.ppt,.pptx';
    const state = {
        activeId: null, group: null, messages: [], hasOlder: false, loadingOlder: false,
        sending: false, groups: config.groups, controller: null, timer: null,
        activatePersonal() {
            if (!this.activeId) return;
            this.controller?.abort();
            this.activeId = null; this.group = null; this.sending = false;
            errorBox.hidden = true;
            document.querySelector('.personal-info-panel').hidden = false;
            document.querySelector('.group-info-panel').hidden = true;
            document.querySelector('.messenger-infoView nav p').textContent = 'Thông tin người dùng';
            form.querySelector('input[type=file]').accept = personalAccept;
            form.querySelector('textarea').maxLength = 5000;
            renderList();
        },
        async send() {
            if (this.sending || !this.activeId || !this.group) return false;
            const groupId = this.activeId;
            const input = form.querySelector('textarea');
            const file = form.querySelector('input[type=file]').files[0];
            if (!input.value.trim() && !file) return false;
            if (file && file.size > 10 * 1024 * 1024) return showError('Tệp nhóm tối đa 10 MB.');
            this.sending = true;
            form.querySelector('.send-button').disabled = true;
            const data = new FormData();
            data.append('body', input.value.trim());
            if (file) data.append('file', file);
            try {
                const result = await socialRequest(this.group.messages_url, { method: 'POST', body: data, signal: this.controller.signal });
                if (groupId !== this.activeId) return;
                merge([result.message]); renderMessages(true);
                window.chatifyUI.resetComposer();
                await refreshGroups();
                input.focus();
            } catch (error) { if (error.name !== 'AbortError') showError(error.message); }
            finally {
                if (this.activeId === groupId) {
                    this.sending = false; form.querySelector('.send-button').disabled = false;
                }
            }
            return false;
        },
    };
    window.messengerGroups = state;

    function showError(message) { errorBox.textContent = message; errorBox.hidden = false; }

    function renderList() {
        list.replaceChildren();
        state.groups.forEach(group => {
            const button = element('button', 'messenger-group-item');
            button.type = 'button'; button.dataset.group = group.id;
            button.classList.toggle('m-list-active', group.id === state.activeId);
            button.setAttribute('aria-label', `Mở nhóm ${group.name}`);
            const icon = element('span', 'group-list-avatar', '👥');
            const text = element('span', 'group-list-copy');
            text.append(element('strong', '', group.name), element('small', '', `Nhóm · ${group.members_count} thành viên`), element('span', 'group-last-message', group.last_message));
            button.append(icon, text);
            button.addEventListener('click', () => selectGroup(group.id));
            list.append(button);
        });
        document.querySelector('.listOfContacts')?.classList.toggle('has-group-conversations', state.groups.length > 0);
    }

    function renderInfo() {
        const group = state.group;
        document.querySelector('.m-header-messaging .user-name').textContent = group.name;
        document.querySelector('.header-avatar').style.backgroundImage = '';
        document.querySelector('.header-avatar').textContent = '👥';
        document.querySelector('.personal-info-panel').hidden = true;
        document.querySelector('.group-info-panel').hidden = false;
        document.querySelector('.messenger-infoView nav p').textContent = 'Thông tin nhóm';
        document.querySelector('[data-group-name]').textContent = group.name;
        const members = document.querySelector('[data-group-members]');
        members.replaceChildren();
        group.members.forEach(member => members.append(element('p', '', member.name + (member.id === group.owner_id ? ' · Quản trị viên' : ''))));
        document.querySelector('[data-add-members]').hidden = group.owner_id !== config.userId;
    }

    async function selectGroup(groupId, detail = null, push = true) {
        state.controller?.abort();
        state.controller = new AbortController();
        state.activeId = Number(groupId); state.group = null; state.messages = []; state.hasOlder = false; state.sending = false;
        errorBox.hidden = true;
        window.chatifyUI.activateGroup();
        form.querySelector('input[type=file]').accept = groupAccept;
        form.querySelector('textarea').maxLength = 5000;
        messages.replaceChildren(element('p', 'group-chat-hint', 'Đang tải tin nhắn nhóm…'));
        renderList();
        if (window.innerWidth <= 980) document.querySelector('.messenger-listView').style.display = 'none';
        try {
            const summary = state.groups.find(group => group.id === Number(groupId));
            const data = detail || (await socialRequest(summary?.info_url || `${config.groupsUrl}/${groupId}`, { signal: state.controller.signal })).group;
            if (state.activeId !== Number(groupId)) return;
            state.group = data;
            renderInfo();
            if (push) pushRoute(`${config.messengerUrl}?group=${groupId}`);
            await fetchMessages(true);
            form.querySelector('textarea').focus();
        } catch (error) { if (error.name !== 'AbortError') showError(error.message); }
    }

    function merge(records) {
        const unique = new Map(state.messages.map(message => [message.id, message]));
        records.forEach(message => unique.set(message.id, message));
        state.messages = [...unique.values()].sort((first, second) => first.id - second.id);
    }

    function renderMessages(scroll = false) {
        messages.replaceChildren();
        if (state.hasOlder) {
            const older = element('button', 'social-button secondary group-older', state.loadingOlder ? 'Đang tải…' : 'Tải tin nhắn cũ');
            older.type = 'button'; older.disabled = state.loadingOlder;
            older.addEventListener('click', loadOlder); messages.append(older);
        }
        if (!state.messages.length) messages.append(element('p', 'group-chat-hint', 'Gửi lời chào để bắt đầu trò chuyện nhóm.'));
        state.messages.forEach(message => {
            const card = element('div', `message-card${message.user_id === config.userId ? ' mc-sender' : ''}`);
            card.dataset.id = `group-${message.id}`;
            const content = element('div', 'message-card-content');
            if (message.user_id !== config.userId) content.append(element('p', 'group-message-author', message.user_name));
            const bubble = element('div', 'message');
            const text = element('span', 'group-message-text', message.body || '');
            bubble.append(text);
            if (message.attachment_url) {
                const link = element('a', 'file-download', message.attachment_name);
                link.href = message.attachment_url; link.target = '_blank'; link.rel = 'noopener';
                if (message.attachment_mime?.startsWith('image/')) {
                    const image = element('img', 'group-chat-image'); image.src = message.attachment_url; image.alt = message.attachment_name;
                    link.replaceChildren(image); image.addEventListener('load', () => { if (scroll) container.scrollTop = container.scrollHeight; });
                }
                bubble.append(link);
            }
            bubble.append(element('sub', 'group-message-date', new Date(message.created_at).toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' })));
            content.append(bubble); card.append(content); messages.append(card);
        });
        if (scroll) container.scrollTop = container.scrollHeight;
    }

    async function fetchMessages(initial = false) {
        if (!state.group || !state.activeId) return;
        const groupId = state.activeId;
        const nearBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 100;
        const lastId = state.messages.at(-1)?.id;
        const query = !initial && lastId ? `?after=${lastId}` : '';
        try {
            const data = await socialRequest(state.group.messages_url + query, { signal: state.controller.signal });
            if (groupId !== state.activeId) return;
            if (!query) state.hasOlder = data.has_more;
            if (initial || data.messages.length) {
                const top = container.scrollTop;
                merge(data.messages); renderMessages(initial || nearBottom);
                if (!initial && !nearBottom) container.scrollTop = top;
            }
        } catch (error) {
            if (error.name !== 'AbortError' && groupId === state.activeId) {
                showError(error.message);
                if ([403, 404].includes(error.status)) {
                    state.controller.abort(); form.querySelector('.send-button').disabled = true;
                }
            }
        }
    }

    async function loadOlder() {
        if (state.loadingOlder || !state.messages.length) return;
        const groupId = state.activeId;
        const height = container.scrollHeight; const top = container.scrollTop;
        state.loadingOlder = true;
        try {
            const data = await socialRequest(`${state.group.messages_url}?before=${state.messages[0].id}`, { signal: state.controller.signal });
            if (state.activeId !== groupId) return;
            merge(data.messages); state.hasOlder = data.has_more; state.loadingOlder = false;
            renderMessages(); container.scrollTop = top + container.scrollHeight - height;
        } catch (error) { if (error.name !== 'AbortError') showError(error.message); }
        finally { state.loadingOlder = false; }
    }

    async function refreshGroups() {
        const data = await socialRequest(config.groupsUrl);
        const changed = JSON.stringify(state.groups) !== JSON.stringify(data.groups);
        state.groups = data.groups;
        if (changed) renderList();
        const selected = state.groups.find(group => group.id === state.activeId);
        if (selected && state.group && (selected.owner_id !== state.group.owner_id || selected.members_count !== state.group.members.length)) {
            const groupId = state.activeId;
            const detail = await socialRequest(selected.info_url, { signal: state.controller.signal });
            if (state.activeId === groupId) { state.group = detail.group; renderInfo(); }
        }
    }

    function openDialog(mode = 'create') {
        const groupForm = dialog.querySelector('form');
        groupForm.reset(); dialog.dataset.mode = mode;
        dialog.querySelector('[data-group-dialog-title]').textContent = mode === 'create' ? 'Tạo nhóm trò chuyện' : 'Thêm thành viên';
        dialog.querySelector('[data-group-name-field]').hidden = mode !== 'create';
        dialog.querySelector('input[name=name]').required = mode === 'create';
        dialog.querySelector('[data-group-dialog-error]').hidden = true;
        const members = dialog.querySelector('[data-group-candidates]'); members.replaceChildren();
        const existing = new Set(mode === 'add' ? state.group.members.map(member => member.id) : []);
        config.users.filter(user => !existing.has(user.id)).forEach(user => {
            const label = element('label', 'group-candidate');
            const checkbox = element('input'); checkbox.type = 'checkbox'; checkbox.name = 'members[]'; checkbox.value = user.id;
            label.append(checkbox, document.createTextNode(user.name)); members.append(label);
        });
        dialog.showModal();
    }

    dialog.querySelector('form').addEventListener('submit', async event => {
        event.preventDefault();
        const button = dialog.querySelector('[type=submit]');
        if (button.disabled) return;
        button.disabled = true;
        const groupId = state.activeId;
        const mode = dialog.dataset.mode;
        try {
            const data = await socialRequest(mode === 'create' ? config.groupStoreUrl : state.group.members_url, { method: 'POST', body: new FormData(event.target) });
            dialog.close(); await refreshGroups();
            if (mode === 'create') await selectGroup(data.group.id, data.group);
            else if (groupId === state.activeId) { state.group = data.group; renderInfo(); }
        } catch (error) {
            const notice = dialog.querySelector('[data-group-dialog-error]'); notice.textContent = error.message; notice.hidden = false;
        } finally { button.disabled = false; }
    });

    document.querySelector('[data-create-group]').addEventListener('click', () => openDialog());
    document.querySelector('[data-add-members]').addEventListener('click', () => openDialog('add'));
    dialog.querySelector('[data-close-group-dialog]').addEventListener('click', () => dialog.close());
    document.querySelector('[data-leave-group]').addEventListener('click', async () => {
        if (!state.group || !confirm('Bạn muốn rời nhóm này?')) return;
        try {
            await socialRequest(state.group.leave_url, { method: 'DELETE' });
            state.activatePersonal(); window.chatifyUI.clearConversation();
            pushRoute(config.messengerUrl); await refreshGroups();
        } catch (error) { showError(error.message); }
    });

    function storyAvatar(event) {
        if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) return;
        const avatar = event.target.closest('.avatar[data-story-user]');
        if (!avatar) return;
        event.preventDefault(); event.stopImmediatePropagation();
        window.dispatchEvent(new CustomEvent('messenger-story-open', { detail: Number(avatar.dataset.storyUser) }));
    }
    document.addEventListener('click', storyAvatar, true);
    document.addEventListener('keydown', storyAvatar, true);
    window.addEventListener('messenger-route-change', () => { currentRoute = location.pathname + location.search; });
    window.addEventListener('popstate', () => {
        if (location.pathname + location.search !== currentRoute) location.reload();
    });
    window.addEventListener('pagehide', () => { clearTimeout(state.timer); state.controller?.abort(); });
    async function poll() {
        if (!document.hidden) {
            try { await refreshGroups(); await fetchMessages(); } catch {}
        }
        state.timer = setTimeout(poll, 2000);
    }
    renderList();
    if (config.selectedGroup) selectGroup(config.selectedGroup.id, config.selectedGroup, false);
    state.timer = setTimeout(poll, 2000);
}
