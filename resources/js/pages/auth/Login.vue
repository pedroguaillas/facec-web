<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { store } from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';

const form = useForm({
    user: '',
    password: '',
    remember: false,
});

function submit() {
    form.post(store.url(), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Iniciar sesión" />

    <div
        class="flex min-h-screen items-center justify-center bg-[#FDFDFC] p-6 dark:bg-[#0a0a0a]"
    >
        <form
            class="w-full max-w-sm rounded-lg bg-white p-8 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d]"
            @submit.prevent="submit"
        >
            <h1
                class="mb-6 text-lg font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Iniciar sesión
            </h1>

            <div class="mb-4">
                <label
                    for="user"
                    class="mb-1 block text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >Usuario</label
                >
                <input
                    id="user"
                    v-model="form.user"
                    type="text"
                    autofocus
                    autocomplete="username"
                    class="w-full rounded-md border border-[#e3e3e0] bg-transparent px-3 py-2 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                />
                <p
                    v-if="form.errors.user"
                    class="mt-1 text-sm text-[#f53003] dark:text-[#FF4433]"
                >
                    {{ form.errors.user }}
                </p>
            </div>

            <div class="mb-6">
                <label
                    for="password"
                    class="mb-1 block text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >Contraseña</label
                >
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    class="w-full rounded-md border border-[#e3e3e0] bg-transparent px-3 py-2 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                />
                <p
                    v-if="form.errors.password"
                    class="mt-1 text-sm text-[#f53003] dark:text-[#FF4433]"
                >
                    {{ form.errors.password }}
                </p>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-sm border border-black bg-[#1b1b18] px-5 py-2 text-sm text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
            >
                {{ form.processing ? 'Ingresando...' : 'Ingresar' }}
            </button>
        </form>
    </div>
</template>
