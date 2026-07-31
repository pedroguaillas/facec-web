<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppHeader from '@/components/layout/AppHeader.vue';
import AppSidebar from '@/components/layout/AppSidebar.vue';

const menuOpen = ref(false);
const page = usePage();

const flashSuccess = computed(() => page.props.flash?.success ?? null);
const flashError = computed(() => page.props.flash?.error ?? null);

function toggleMenu() {
    menuOpen.value = !menuOpen.value;
}

function closeMenu() {
    menuOpen.value = false;
}
</script>

<template>
    <div class="min-h-screen bg-background">
        <AppSidebar :open="menuOpen" @close="closeMenu" />

        <div
            class="flex min-h-screen flex-col transition-all duration-300 ease-in-out md:pl-[4.5rem]"
        >
            <AppHeader @toggle-menu="toggleMenu" />

            <main class="flex-1 p-6">
                <div
                    v-if="flashSuccess"
                    class="mb-4 rounded-lg border border-success/20 bg-success/10 px-4 py-3 text-sm text-success"
                >
                    {{ flashSuccess }}
                </div>
                <div
                    v-if="flashError"
                    class="mb-4 rounded-lg border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger"
                >
                    {{ flashError }}
                </div>

                <slot />
            </main>
        </div>
    </div>
</template>
