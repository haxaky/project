import Alpine from 'alpinejs';

export async function socialRequest(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin', ...options,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, ...options.headers },
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const messages = Object.values(data.errors || {}).flat();
        const error = new Error(messages.join(' ') || (response.status === 413 ? 'Tệp vượt giới hạn tải lên của máy chủ. Hãy chọn tệp nhỏ hơn.' : data.message) || 'Không kết nối được máy chủ. Vui lòng thử lại.');
        error.status = response.status;
        throw error;
    }
    return data;
}

Alpine.data('storyFeed', () => ({
    root: null, stories: [], userId: null, activeIndex: -1, progress: 0,
    timer: null, expiryTimer: null, feedTimer: null, observer: null, controller: null,
    paused: false, viewerError: '', viewers: [], showViewers: false,
    previewUrl: '', previewVideo: false, musicPreviewUrl: '', musicTitle: '',
    musicStart: 0, musicDuration: 15, musicAudio: null, musicBlocked: false,
    uploadError: '', uploadStatus: '', publishing: false, composerOpen: false, background: 'indigo', caption: '',

    init() {
        this.root = this.$el;
        this.userId = window.messengerSocial.userId;
        this.controller = new AbortController();
        this.setStories(window.messengerSocial.stories);
        this.composerOpen = new URL(location.href).searchParams.get('story') === 'create';
        this.observer = new MutationObserver(() => this.updateAvatarBadges());
        this.observer.observe(document.querySelector('.contacts-container'), { childList: true, subtree: true });
        this.expiryTimer = setInterval(() => {
            const activeId = this.active?.id;
            this.stories = this.stories.filter(story => new Date(story.expires_at) > new Date());
            this.activeIndex = activeId ? this.stories.findIndex(story => story.id === activeId) : -1;
            if (activeId && this.activeIndex < 0) this.close();
            this.updateAvatarBadges();
        }, 1000);
        this.pollFeed();
    },

    destroy() {
        clearInterval(this.timer);
        clearInterval(this.expiryTimer);
        clearTimeout(this.feedTimer);
        this.observer?.disconnect();
        this.controller.abort();
        this.stopMusic();
        if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
        if (this.musicPreviewUrl) URL.revokeObjectURL(this.musicPreviewUrl);
    },

    setStories(stories) {
        const activeId = this.active?.id;
        const viewed = new Set(this.stories.filter(story => story.viewed).map(story => story.id));
        this.stories = stories.map(story => ({ ...story, viewed: story.viewed || viewed.has(story.id) }));
        this.stories.sort((first, second) => Number(second.user_id === this.userId) - Number(first.user_id === this.userId) || first.user_id - second.user_id || first.id - second.id);
        this.activeIndex = activeId ? this.stories.findIndex(story => story.id === activeId) : -1;
        if (activeId && this.activeIndex < 0) this.close();
        this.updateAvatarBadges();
    },

    pollFeed() {
        this.feedTimer = setTimeout(async () => {
            try {
                if (!document.hidden) {
                    const data = await socialRequest(window.messengerSocial.storiesUrl, { signal: this.controller.signal });
                    this.setStories(data.stories);
                }
            } catch (error) {
                if ([401, 419].includes(error.status)) this.uploadError = 'Phiên đăng nhập đã hết hạn. Vui lòng tải lại trang.';
            }
            if (!this.controller.signal.aborted) this.pollFeed();
        }, 5000);
    },

    get active() { return this.stories[this.activeIndex] || null; },

    get people() {
        const people = new Map();
        this.stories.forEach(story => {
            if (!people.has(story.user_id)) people.set(story.user_id, { ...story, stories: [], unseen: false });
            const person = people.get(story.user_id);
            person.stories.push(story);
            if (!story.viewed && story.user_id !== this.userId) person.unseen = true;
        });
        return [...people.values()].sort((first, second) => Number(second.user_id === this.userId) - Number(first.user_id === this.userId));
    },

    updateAvatarBadges() {
        const people = new Map(this.people.map(person => [String(person.user_id), person]));
        document.querySelectorAll('.messenger-list-item[data-contact] .avatar:not(.saved-messages)').forEach(avatar => {
            const person = people.get(avatar.closest('[data-contact]').dataset.contact);
            avatar.classList.toggle('has-story', !!person);
            avatar.classList.toggle('story-unseen', !!person?.unseen);
            if (person) {
                avatar.dataset.storyUser = person.user_id;
                avatar.title = `Xem story của ${person.user_name}`;
                avatar.setAttribute('role', 'button');
                avatar.setAttribute('tabindex', '0');
            } else if (avatar.dataset.storyUser) {
                delete avatar.dataset.storyUser;
                avatar.removeAttribute('title');
                avatar.removeAttribute('role');
                avatar.removeAttribute('tabindex');
            }
        });
    },

    openByUser(userId) {
        const person = this.people.find(person => person.user_id === Number(userId));
        if (person) this.openPerson(person);
    },

    openPerson(person) {
        const first = person.stories.find(story => !story.viewed) || person.stories[0];
        this.open(this.stories.findIndex(story => story.id === first.id));
    },

    async open(index) {
        clearInterval(this.timer);
        this.stopMusic();
        this.activeIndex = index;
        if (!this.active) return this.close();
        const story = this.active;
        this.progress = 0;
        this.paused = false;
        this.viewerError = '';
        this.showViewers = false;
        this.viewers = [];
        this.musicBlocked = false;
        if (story.music_url) {
            const audio = new Audio(story.music_url);
            this.musicAudio = audio;
            audio.volume = 0.65;
            audio.addEventListener('loadedmetadata', () => {
                audio.currentTime = Math.min(story.music_start, Math.max(0, audio.duration - 0.1));
            }, { once: true });
            audio.play().catch(() => { if (this.active?.id === story.id) this.musicBlocked = true; });
        }
        this.timer = setInterval(() => {
            if (this.paused || this.showViewers || document.hidden || (this.active?.is_video && !this.active?.music_url)) return;
            const duration = this.active?.music_url ? this.active.music_duration : 8;
            this.progress = Math.min(100, this.progress + 8 / duration);
            if (this.progress >= 100) this.next();
        }, 80);
        try {
            await socialRequest(story.view_url, { method: 'POST', signal: this.controller.signal });
            story.viewed = true;
            this.updateAvatarBadges();
        } catch (error) {
            if (this.active?.id !== story.id || error.name === 'AbortError') return;
            this.viewerError = error.message;
            this.setPaused(true);
        }
        await this.$nextTick();
        this.root.querySelector('[data-close-story]')?.focus();
    },

    stopMusic() {
        if (!this.musicAudio) return;
        this.musicAudio.pause();
        this.musicAudio.removeAttribute('src');
        this.musicAudio.load();
        this.musicAudio = null;
    },

    setPaused(value) {
        this.paused = value;
        const video = this.root.querySelector('.story-viewer video');
        if (value || this.showViewers) {
            this.musicAudio?.pause();
            video?.pause();
        } else {
            const storyId = this.active?.id;
            this.musicAudio?.play().then(() => { this.musicBlocked = false; }).catch(() => { if (this.active?.id === storyId) this.musicBlocked = true; });
            video?.play().catch(() => {});
        }
    },

    next() { this.activeIndex + 1 >= this.stories.length ? this.close() : this.open(this.activeIndex + 1); },
    previous() { this.open(Math.max(0, this.activeIndex - 1)); },

    closeComposer() {
        if (this.publishing) return;
        this.composerOpen = false;
        this.root.querySelectorAll('#story-form audio, .story-preview video').forEach(media => media.pause());
        this.$nextTick(() => this.root.querySelector('[data-new-story]')?.focus());
    },

    close() {
        clearInterval(this.timer);
        this.stopMusic();
        this.activeIndex = -1;
        this.showViewers = false;
        this.$nextTick(() => this.root.querySelector('[data-new-story]')?.focus());
    },

    trapFocus(event) {
        const dialog = event.target.closest('[role="dialog"]');
        const controls = [...dialog.querySelectorAll('button, input, textarea, select, video[controls], audio[controls]')]
            .filter(control => !control.disabled && control.getClientRects().length);
        if (event.shiftKey && event.target === controls[0]) {
            event.preventDefault(); controls.at(-1)?.focus();
        } else if (!event.shiftKey && event.target === controls.at(-1)) {
            event.preventDefault(); controls[0]?.focus();
        }
    },

    async loadViewers() {
        this.showViewers = !this.showViewers;
        this.setPaused(this.showViewers);
        if (!this.showViewers) return;
        const storyId = this.active.id;
        try {
            const data = await socialRequest(this.active.viewers_url, { signal: this.controller.signal });
            if (this.active?.id !== storyId) return;
            this.viewers = data.viewers;
            this.active.views_count = data.viewers.length;
        } catch (error) {
            if (this.active?.id === storyId) this.viewerError = error.message;
        }
    },

    async publish() {
        if (this.publishing) return;
        this.publishing = true;
        this.uploadError = '';
        this.uploadStatus = 'Đang đăng story…';
        let musicUpload = null;
        try {
            const form = new FormData(this.root.querySelector('#story-form'));
            const music = form.get('music');
            if (music?.size) {
                this.uploadStatus = 'Đang tải nhạc: 0%';
                musicUpload = await socialRequest(window.messengerSocial.musicUploadUrl, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, signal: this.controller.signal,
                    body: JSON.stringify({ name: music.name, size: music.size, start: Number(form.get('music_start') || 0), duration: Number(form.get('music_duration') || 15) }),
                });
                for (let offset = 0; offset < music.size; offset += musicUpload.chunk_size) {
                    const chunk = new FormData();
                    chunk.set('offset', offset);
                    chunk.set('chunk', music.slice(offset, offset + musicUpload.chunk_size), 'chunk.bin');
                    const uploaded = await socialRequest(musicUpload.upload_url, { method: 'POST', body: chunk, signal: this.controller.signal });
                    this.uploadStatus = `Đang tải nhạc: ${Math.round(uploaded.received / music.size * 100)}%`;
                }
                this.uploadStatus = 'Đang tự cắt đoạn nhạc…';
                const clip = await socialRequest(musicUpload.finish_url, { method: 'POST', signal: this.controller.signal });
                form.delete('music');
                form.set('music_token', clip.music_token);
            } else {
                form.delete('music');
            }
            this.uploadStatus = 'Đang đăng story…';
            const data = await socialRequest(window.messengerSocial.storyStoreUrl, {
                method: 'POST', body: form, signal: this.controller.signal,
            });
            musicUpload = null;
            this.setStories(data.stories);
            this.publishing = false;
            this.closeComposer();
            this.root.querySelector('#story-form').reset();
            this.caption = ''; this.background = 'indigo'; this.musicTitle = ''; this.musicStart = 0; this.musicDuration = 15;
            if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
            if (this.musicPreviewUrl) URL.revokeObjectURL(this.musicPreviewUrl);
            this.previewUrl = ''; this.musicPreviewUrl = '';
        } catch (error) {
            if (error.name !== 'AbortError') this.uploadError = error.message;
        } finally {
            if (musicUpload) await socialRequest(musicUpload.cancel_url, { method: 'DELETE' }).catch(() => {});
            this.publishing = false;
            this.uploadStatus = '';
        }
    },

    async deleteActive() {
        if (!confirm('Xóa story này?')) return;
        const story = this.active;
        try {
            await socialRequest(story.delete_url, { method: 'DELETE', signal: this.controller.signal });
            this.close();
            this.stories = this.stories.filter(item => item.id !== story.id);
            this.updateAvatarBadges();
        } catch (error) { this.viewerError = error.message; }
    },

    preview(event, music = false) {
        const property = music ? 'musicPreviewUrl' : 'previewUrl';
        if (this[property]) URL.revokeObjectURL(this[property]);
        this[property] = '';
        this.uploadError = '';
        const file = event.target.files[0];
        if (!file) return;
        if (file.size > (music ? 50 : 20) * 1024 * 1024) {
            this.uploadError = music ? 'Nhạc tối đa 50 MB.' : 'Ảnh/video tối đa 20 MB.';
            event.target.value = ''; return;
        }
        if (music) { this.musicTitle = file.name.slice(0, 100); this.musicStart = 0; }
        else this.previewVideo = file.type.startsWith('video/');
        this[property] = URL.createObjectURL(file);
    },

    age(timestamp) {
        const minutes = Math.max(1, Math.floor((Date.now() - new Date(timestamp).getTime()) / 60000));
        return minutes < 60 ? `${minutes} phút trước` : `${Math.floor(minutes / 60)} giờ trước`;
    },
}));
