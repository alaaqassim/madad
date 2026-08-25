import './bootstrap';
import { createApp } from 'vue';
import MadadApp from './MadadApp.vue';

const el = document.getElementById('app');

if (el) {
    createApp(MadadApp).mount(el);
}
