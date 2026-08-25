<script setup>
import { computed, ref } from 'vue';
import MadadCard from '../components/madad/MadadCard.vue';
import MadadButton from '../components/madad/MadadButton.vue';
import MadadField from '../components/madad/MadadField.vue';
import MadadStatusMessage from '../components/madad/MadadStatusMessage.vue';
import { copy, messageForReason } from '../i18n/messages';

/*
| Contestant sign-in.
|
| Accounts are provisioned by the operators (artisan madad:*) and the
| credentials are emailed out, so there is no registration here by design.
| Fields mirror LoginRequest exactly: email and password, nothing else.
*/
const props = defineProps({
    loading: { type: Boolean, default: false },
    /** A backend reason code, or null. */
    errorReason: { type: String, default: null },
    /** Laravel's per-field errors, used only to decide WHICH field to mark. */
    fieldErrors: { type: Object, default: null },
});

const emit = defineEmits(['submit']);

const email = ref('');
const password = ref('');
const revealPassword = ref(false);
const attempted = ref(false);

const emailMissing = computed(() => attempted.value && email.value.trim() === '');
const passwordMissing = computed(() => attempted.value && password.value === '');

/*
| The banner always speaks Arabic chosen from the reason code. A wrong password
| and an unknown address produce the same code — and so the same sentence — so
| the screen cannot be used to discover who is registered.
*/
const banner = computed(() => (props.errorReason ? messageForReason(props.errorReason) : null));

/*
| A credential failure is a property of the pair, not of one field, and the
| backend deliberately returns the same message either way. So the banner
| explains it once and both controls are merely marked invalid and pointed at
| that banner — repeating the sentence under each input says nothing new and
| makes a screen reader read it three times.
*/
const credentialFailure = computed(
    () => props.errorReason === 'invalid_credentials' || props.errorReason === 'too_many_attempts',
);

const emailFieldError = computed(() => (emailMissing.value ? copy.login.required : null));
const passwordFieldError = computed(() => (passwordMissing.value ? copy.login.required : null));

const emailInvalid = computed(() => credentialFailure.value || Boolean(props.fieldErrors?.email));
const passwordInvalid = computed(() => credentialFailure.value || Boolean(props.fieldErrors?.password));

const bannerId = 'madad-login-banner';

function submit() {
    attempted.value = true;

    if (email.value.trim() === '' || password.value === '') {
        return;
    }

    emit('submit', { email: email.value.trim(), password: password.value });
}
</script>

<template>
    <div>
        <div class="madad-intro">
            <p class="madad-eyebrow"><span>{{ copy.login.eyebrow }}</span></p>
            <h1 class="madad-intro__title">{{ copy.login.title }}</h1>
            <p class="madad-intro__subtitle">{{ copy.login.subtitle }}</p>
        </div>

        <MadadCard :heading="copy.login.section">
            <MadadStatusMessage
                v-if="banner"
                :id="bannerId"
                class="login__banner"
                tone="error"
                :message="banner"
                :retryable="errorReason === 'network_error' || errorReason === 'server_error'"
                :busy="loading"
                @retry="submit"
            />

            <form novalidate @submit.prevent="submit">
                <MadadField
                    v-model="email"
                    :label="copy.login.email"
                    type="email"
                    :placeholder="copy.login.emailPlaceholder"
                    autocomplete="username"
                    inputmode="email"
                    :disabled="loading"
                    required
                    ltr
                    :error="emailFieldError"
                    :invalid="emailInvalid"
                    :described-by="banner ? bannerId : null"
                />

                <MadadField
                    v-model="password"
                    :label="copy.login.password"
                    :type="revealPassword ? 'text' : 'password'"
                    :placeholder="copy.login.passwordPlaceholder"
                    autocomplete="current-password"
                    :disabled="loading"
                    required
                    :error="passwordFieldError"
                    :invalid="passwordInvalid"
                    :described-by="banner ? bannerId : null"
                >
                    <template #hint>
                        <button type="button" class="login__reveal" @click="revealPassword = !revealPassword">
                            {{ revealPassword ? copy.login.hidePassword : copy.login.showPassword }}
                        </button>
                    </template>
                </MadadField>

                <MadadButton class="login__submit" type="submit" variant="gold" block arrow :loading="loading">
                    {{ loading ? copy.login.submitting : copy.login.submit }}
                </MadadButton>
            </form>
        </MadadCard>
    </div>
</template>

<style scoped>
.login__banner {
    margin-bottom: var(--madad-space-6);
}

.login__reveal {
    margin-top: var(--madad-space-2);
    padding: var(--madad-space-1) 0;
    min-height: var(--madad-tap-min);
    background: none;
    border: 0;
    color: var(--madad-green-800);
    font-size: var(--madad-text-sm);
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 4px;
    cursor: pointer;
}

.login__reveal:hover {
    color: var(--madad-gold-700);
}

.login__submit {
    margin-top: var(--madad-space-8);
}
</style>
