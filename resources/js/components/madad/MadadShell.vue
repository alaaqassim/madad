<script setup>
import MadadHeader from './MadadHeader.vue';

/*
| The page shell every contestant screen sits in: cream page, Madad header,
| a reading-width main column. `dir="rtl"` is set on <html> in app.blade.php —
| this component composes with logical properties rather than re-flipping.
*/
</script>

<template>
    <div class="madad-page">
        <MadadHeader>
            <template #actions>
                <slot name="header-actions" />
            </template>
        </MadadHeader>

        <main class="madad-main" id="madad-main">
            <div class="madad-reading">
                <slot />
            </div>
        </main>

        <footer class="madad-footer">
            <p class="madad-footer__note">
                <slot name="footer">مداد الغدير — المسابقة الطلابيّة</slot>
            </p>
        </footer>
    </div>
</template>

<style scoped>
.madad-footer {
    padding: var(--madad-space-6) var(--madad-space-4) var(--madad-space-8);
    border-top: 1px solid var(--madad-border);
    background: var(--madad-cream);
}

.madad-footer__note {
    max-width: var(--madad-container-wide);
    margin-inline: auto;
    text-align: center;
    color: var(--madad-ink-muted);
    font-size: var(--madad-text-sm);
}
</style>
