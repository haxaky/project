<dialog id="group-dialog" class="chat-social-dialog" aria-labelledby="group-dialog-title">
    <div class="social-dialog-heading"><h2 id="group-dialog-title" data-group-dialog-title>Tạo nhóm trò chuyện</h2><button type="button" class="social-close" data-close-group-dialog aria-label="Đóng tạo nhóm">×</button></div>
    <form class="social-form">
        <p class="social-muted">Nhóm sử dụng cùng khung nhắn tin hiện tại, không chuyển sang trang khác.</p>
        <div class="social-alert error" data-group-dialog-error role="alert" hidden></div>
        <div data-group-name-field><label for="group-name" class="social-label">Tên nhóm</label><input id="group-name" name="name" maxlength="100" required class="social-field" placeholder="Ví dụ: Hội bạn thân"></div>
        <fieldset><legend class="social-label">Chọn thành viên</legend><div class="group-candidates" data-group-candidates></div></fieldset>
        <button class="social-button" type="submit">Xác nhận</button>
    </form>
</dialog>

<div class="social-overlay" x-show="composerOpen" x-cloak @click.self="closeComposer" role="dialog" aria-modal="true" aria-labelledby="story-compose-title" @keydown.tab="trapFocus($event)">
    <section class="story-compose-dialog">
        <div class="social-dialog-heading"><div><h2 id="story-compose-title">Tạo story</h2><p class="social-muted">Chia sẻ ngay trong tin nhắn · tự ẩn sau 24 giờ</p></div><button type="button" class="social-close" @click="closeComposer" aria-label="Đóng tạo story">×</button></div>
        <div class="story-compose-grid">
            <form id="story-form" class="social-form" @submit.prevent="publish">
                <div class="social-alert error" role="alert" x-show="uploadError" x-text="uploadError" x-cloak></div>
                <div><label for="story-caption" class="social-label">Bạn muốn chia sẻ gì?</label><textarea id="story-caption" name="body" x-model="caption" class="social-field" rows="3" maxlength="1000" placeholder="Một khoảnh khắc hôm nay…"></textarea></div>
                <fieldset><legend class="social-label">Màu nền</legend><div class="story-backgrounds">
                    @foreach (['indigo' => 'Tím', 'rose' => 'Hồng', 'emerald' => 'Xanh lá', 'amber' => 'Cam', 'slate' => 'Xám'] as $color => $label)
                        <label title="{{ $label }}"><input type="radio" name="background" value="{{ $color }}" x-model="background"><span class="story-color story-bg-{{ $color }}" :class="{ selected: background === '{{ $color }}' }"></span><span class="social-sr-only">{{ $label }}</span></label>
                    @endforeach
                </div></fieldset>
                <div><label for="story-media" class="social-label">Ảnh/video</label><input id="story-media" name="media" type="file" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm" @change="preview($event)" class="social-field"><p class="social-muted">Tối đa 20 MB · Không bắt buộc</p></div>
                <div class="story-music-settings">
                    <label for="story-music" class="social-label">♫ Thêm nhạc vào story</label>
                    <input id="story-music" name="music" type="file" accept="audio/mpeg,audio/mp4,audio/ogg,audio/wav,.mp3,.m4a,.ogg,.wav" @change="preview($event, true)" class="social-field">
                    <p class="social-muted">MP3, M4A, OGG, WAV · tối đa 50 MB · tự cắt 15 giây, không cần cắt file trước. Chỉ tải nhạc bạn có quyền sử dụng.</p>
                    <div x-show="musicPreviewUrl" x-cloak class="story-music-fields">
                        <label for="music-title" class="social-label">Tên bài nhạc</label><input id="music-title" name="music_title" x-model="musicTitle" maxlength="100" class="social-field">
                        <div class="story-music-range"><div><label for="music-start" class="social-label">Bắt đầu (giây)</label><input id="music-start" name="music_start" x-model="musicStart" type="number" min="0" max="3600" class="social-field"></div><div><label for="music-duration" class="social-label">Đoạn nhạc (giây)</label><input id="music-duration" name="music_duration" x-model="musicDuration" type="number" min="5" max="30" class="social-field"></div></div>
                        <template x-if="musicPreviewUrl"><audio :src="musicPreviewUrl" controls x-on:loadedmetadata="$el.currentTime = Math.min(Number(musicStart), Math.max(0, $el.duration - 0.1))" @play="$el.currentTime = Math.min(Number(musicStart), Math.max(0, $el.duration - 0.1))" @timeupdate="if ($el.currentTime >= Number(musicStart) + Number(musicDuration)) $el.pause()"></audio></template>
                    </div>
                </div>
                <p class="social-muted">Story hiển thị với mọi tài khoản đã đăng nhập trong ứng dụng.</p>
                <p class="social-muted" role="status" aria-live="polite" x-show="publishing" x-text="uploadStatus" x-cloak></p>
                <button type="submit" class="social-button" :disabled="publishing || (!caption.trim() && !previewUrl && !musicPreviewUrl)" x-text="publishing ? 'Đang xử lý…' : 'Đăng story'"></button>
            </form>
            <div class="story-preview-wrap"><p class="social-label">Xem trước</p><div class="story-preview" :class="'story-bg-' + background">
                <template x-if="previewUrl && !previewVideo"><img :src="previewUrl" alt="Ảnh story xem trước"></template>
                <template x-if="previewUrl && previewVideo"><video :src="previewUrl" muted autoplay loop playsinline></video></template>
                <p class="story-preview-caption" x-text="caption || (previewUrl ? '' : 'Khoảnh khắc của bạn ✨')"></p>
                <span class="story-preview-music" x-show="musicPreviewUrl" x-text="'♫ ' + musicTitle"></span>
            </div></div>
        </div>
    </section>
</div>

<template x-if="active">
    <div class="story-overlay" role="dialog" aria-modal="true" aria-label="Xem story" @click.self="close" @keydown.tab="trapFocus($event)">
        <button type="button" class="story-control story-previous" @click="previous" :disabled="activeIndex === 0" aria-label="Story trước">‹</button>
        <section class="story-viewer" :class="'story-bg-' + (active?.background || 'indigo')">
            <template x-for="story in (active ? [active] : [])" :key="story.id"><div>
                <template x-if="story.media_url && !story.is_video"><img :src="story.media_url" alt="Ảnh story" class="story-viewer-media" x-on:error="viewerError = 'Tin đã hết hạn, bị xóa hoặc không tải được ảnh.'; setPaused(true)"></template>
                <template x-if="story.media_url && story.is_video"><video :src="story.media_url" :muted="!!story.music_url" controls autoplay playsinline class="story-viewer-media" @ended="if (!story.music_url) next()" @timeupdate="if (!story.music_url) progress = $event.target.duration ? Math.min(100, $event.target.currentTime / $event.target.duration * 100) : 0" x-on:error="viewerError = 'Không phát được video.'"></video></template>
            </div></template>
            <div class="story-progress"><div class="story-progress-fill" :style="{ width: progress + '%' }"></div></div>
            <div class="story-viewer-header"><img :src="active?.avatar" alt="" class="social-avatar"><div class="story-viewer-owner"><strong x-text="active?.user_name"></strong><small x-text="active ? age(active.created_at) : ''"></small></div><button type="button" class="story-control" @click="setPaused(!paused)" :aria-label="paused ? 'Tiếp tục story' : 'Tạm dừng story'" x-text="paused ? '▶' : 'Ⅱ'"></button><button type="button" class="story-control" data-close-story @click="close" aria-label="Đóng story">×</button></div>
            <p class="story-viewer-caption" :class="{ 'with-media': active?.media_url }" x-show="active?.body" x-text="active?.body"></p>
            <div class="story-viewer-music" x-show="active?.music_url"><span x-text="'♫ ' + (active?.music_title || '')"></span><button type="button" x-show="musicBlocked" @click="setPaused(false)" class="social-button secondary">Bật nhạc</button></div>
            <div class="story-viewer-error social-alert error" role="alert" x-show="viewerError" x-text="viewerError"></div>
            <div class="story-viewer-footer">
                <template x-if="active?.user_id === userId"><button type="button" class="social-button secondary" @click="loadViewers" x-text="'◉ ' + (active?.views_count || 0) + ' người xem'"></button></template>
                <template x-if="active?.user_id === userId"><button class="social-button danger" type="button" @click="deleteActive">Xóa tin</button></template>
                <span x-show="active?.user_id !== userId">← → Chuyển tin · Esc để đóng</span>
            </div>
            <div class="story-viewers" x-show="showViewers"><h3>Người đã xem</h3><p class="social-muted" x-show="!viewers.length">Chưa có người xem.</p><template x-for="viewer in viewers" :key="viewer.id"><p x-text="viewer.name"></p></template></div>
        </section>
        <button type="button" class="story-control story-next" @click="next" aria-label="Story tiếp theo">›</button>
    </div>
</template>
