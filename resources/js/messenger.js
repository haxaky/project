import $ from 'jquery';
import NProgress from 'nprogress';
import 'nprogress/nprogress.css';
import EmojiButton from '@joeattardi/emoji-button';
import Pusher from 'pusher-js';

Object.assign(window, { $, jQuery: $, NProgress, EmojiButton, Pusher });

// Preserve the package scripts' shared globals while serving all assets locally.
async function initializeMessenger() {
    for (const path of ['js/chatify/utils.js', 'js/chatify/code.js']) {
        await new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = new URL(path, window.location.origin + '/').href;
            script.onload = resolve;
            script.onerror = reject;
            document.body.appendChild(script);
        });
    }
}
initializeMessenger().catch(console.error);
