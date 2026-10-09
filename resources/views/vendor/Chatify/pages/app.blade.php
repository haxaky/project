<!DOCTYPE html>
<html lang="vi">
<head>
@include('Chatify::layouts.headLinks')
</head>
<body x-data="storyFeed" @messenger-story-open.window="openByUser($event.detail)" @keydown.escape.window="if (active) close(); else if (composerOpen) closeComposer();" @keydown.right.window="if (active && !['INPUT', 'TEXTAREA', 'VIDEO'].includes($event.target.tagName)) next()" @keydown.left.window="if (active && !['INPUT', 'TEXTAREA', 'VIDEO'].includes($event.target.tagName)) previous()">
<div class="messenger" :inert="active !== null || composerOpen">
    {{-- ----------------------Users/Groups lists side---------------------- --}}
    <div class="messenger-listView {{ !!$id ? 'conversation-active' : '' }}">
        {{-- Header and search bar --}}
        <div class="m-header">
            <nav>
                <a href="#"><i class="fas fa-inbox"></i> <span class="messenger-headTitle">Tin nhắn</span> </a>
                {{-- header buttons --}}
                <nav class="m-header-right">
                    <button type="button" class="messenger-create-group" data-create-group title="Tạo nhóm trò chuyện" aria-label="Tạo nhóm trò chuyện"><i class="fas fa-users"></i></button>
                    <a href="#"><i class="fas fa-cog settings-btn"></i></a>
                    <a href="#" class="listView-x"><i class="fas fa-times"></i></a>
                </nav>
            </nav>
            {{-- Search input --}}
            <input type="text" class="messenger-search" placeholder="Tìm người dùng" />
            @include('Chatify::layouts.storyStrip')
            {{-- Tabs --}}
            {{-- <div class="messenger-listView-tabs">
                <a href="#" class="active-tab" data-view="users">
                    <span class="far fa-user"></span> Contacts</a>
            </div> --}}
        </div>
        {{-- tabs and lists --}}
        <div class="m-body contacts-container">
           {{-- Lists [Users/Group] --}}
           {{-- ---------------- [ User Tab ] ---------------- --}}
           <div class="show messenger-tab users-tab app-scroll" data-view="users">
               {{-- Yêu thích --}}
               <div class="favorites-section">
                <p class="messenger-title"><span>Yêu thích</span></p>
                <div class="messenger-favorites app-scroll-hidden"></div>
               </div>
               {{-- Ghi chú của tôi --}}
               <p class="messenger-title"><span>Không gian của tôi</span></p>
               {!! view('Chatify::layouts.listItem', ['get' => 'saved']) !!}
               {{-- Contact --}}
               <p class="messenger-title"><span>Cuộc trò chuyện</span></p>
               <div class="listOfGroups"></div>
               <div class="listOfContacts" style="width: 100%;position: relative;"></div>
           </div>
             {{-- ---------------- [ Search Tab ] ---------------- --}}
           <div class="messenger-tab search-tab app-scroll" data-view="search">
                {{-- items --}}
                <p class="messenger-title"><span>Tìm kiếm</span></p>
                <div class="search-records">
                    <p class="message-hint center-el"><span>Nhập tên để tìm kiếm...</span></p>
                </div>
             </div>
        </div>
    </div>

    {{-- ----------------------Messaging side---------------------- --}}
    <div class="messenger-messagingView">
        {{-- header title [conversation name] amd buttons --}}
        <div class="m-header m-header-messaging">
            <nav class="chatify-d-flex chatify-justify-content-between chatify-align-items-center">
                {{-- header back button, avatar and user name --}}
                <div class="chatify-d-flex chatify-justify-content-between chatify-align-items-center">
                    <a href="#" class="show-listView"><i class="fas fa-arrow-left"></i></a>
                    <div class="avatar av-s header-avatar" style="margin: 0px 10px; margin-top: -5px; margin-bottom: -5px;">
                    </div>
                    <a href="#" class="user-name">{{ config('chatify.name') }}</a>
                </div>
                {{-- header buttons --}}
                <nav class="m-header-right">
                    <a href="#" class="add-to-favorite"><i class="fas fa-star"></i></a>
                    <a href="{{ route('dashboard') }}" title="Tài khoản cá nhân"><i class="fas fa-home"></i></a>
                    <a href="{{ route('profile.edit') }}" title="Hồ sơ cá nhân"><i class="fas fa-user"></i></a>
                    <a href="#" class="show-infoSide"><i class="fas fa-info-circle"></i></a>
                </nav>
            </nav>
            {{-- Internet connection --}}
            <div class="internet-connection">
                <span class="ic-connected">Đã kết nối</span>
                <span class="ic-connecting">Đang kết nối...</span>
                <span class="ic-noInternet">Không kết nối được máy chủ</span>
            </div>
        </div>
        <div id="group-chat-error" class="social-alert error" role="alert" hidden></div>

        {{-- Messaging area --}}
        <div class="m-body messages-container app-scroll">
            <div class="messages">
                <p class="message-hint center-el"><span>Chọn cuộc trò chuyện hoặc mở Ghi chú của tôi</span></p>
            </div>
            {{-- Typing indicator --}}
            <div class="typing-indicator">
                <div class="message-card typing">
                    <div class="message">
                        <span class="typing-dots">
                            <span class="dot dot-1"></span>
                            <span class="dot dot-2"></span>
                            <span class="dot dot-3"></span>
                        </span>
                    </div>
                </div>
            </div>

        </div>
        {{-- Send Message Form --}}
        @include('Chatify::layouts.sendForm')
    </div>
    {{-- ---------------------- Info side ---------------------- --}}
    <div class="messenger-infoView app-scroll">
        {{-- nav actions --}}
        <nav>
            <p>Thông tin người dùng</p>
            <a href="#"><i class="fas fa-times"></i></a>
        </nav>
        <div class="personal-info-panel">{!! view('Chatify::layouts.info')->render() !!}</div>
        <div class="group-info-panel" hidden>
            <div class="group-info-avatar">👥</div>
            <h3 data-group-name></h3>
            <h4>Thành viên</h4>
            <div data-group-members></div>
            <button type="button" class="social-button secondary" data-add-members>Thêm thành viên</button>
            <button type="button" class="social-button danger" data-leave-group>Rời nhóm</button>
        </div>
    </div>
</div>

@include('Chatify::layouts.modals')
@include('Chatify::layouts.footerLinks')
<script>window.messengerSocial = {{ Illuminate\Support\Js::from($messengerSocial) }};</script>
@include('Chatify::layouts.socialModals')

</body>
</html>
