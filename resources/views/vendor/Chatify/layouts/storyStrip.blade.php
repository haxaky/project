<div class="messenger-story-strip" aria-label="Story trong tin nhắn">
    <button type="button" class="messenger-story-person story-create" data-new-story @click="composerOpen = true; $nextTick(() => root.querySelector('#story-caption').focus())" aria-label="Đăng story">
        <span class="story-ring"><img src="{{ Chatify::getUserAvatarUrl(auth()->user()->avatar) }}" alt=""><span class="story-add-icon">＋</span></span>
        <span>Tạo story</span>
    </button>
    <template x-for="person in people" :key="person.user_id">
        <button type="button" class="messenger-story-person" :class="person.unseen ? 'unseen' : 'seen'" @click="openPerson(person)" :aria-label="'Xem story của ' + person.user_name">
            <span class="story-ring"><img :src="person.avatar" alt=""><span class="story-music-icon" x-show="person.stories.some(story => story.music_url)">♫</span></span>
            <span x-text="person.user_id === userId ? 'Tin của bạn' : person.user_name"></span>
        </button>
    </template>
</div>
