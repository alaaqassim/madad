import './bootstrap';
import { createApp } from 'vue';
import BootstrapCheck from './components/BootstrapCheck.vue';

const el = document.getElementById('app');

if (el) {
    createApp(BootstrapCheck).mount(el);
}
