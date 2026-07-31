<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { destroy } from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';

defineEmits<{
    'toggle-menu': [];
}>();

const page = usePage();
const userName = computed(() => page.props.auth.user.name ?? 'Usuario');
const initial = computed(() => userName.value.charAt(0).toUpperCase());
</script>

<template>
    <header
        class="sticky top-0 z-40 flex h-14 items-center justify-between border-b border-border bg-card px-4"
    >
        <button
            class="-ml-2 rounded-lg p-2 text-foreground transition-colors duration-150 hover:bg-accent"
            @click="$emit('toggle-menu')"
        >
            <svg
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
                class="h-5 w-5"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"
                />
            </svg>
        </button>

        <div class="flex items-center gap-3">
            <div class="flex items-center gap-2.5">
                <Avatar class="h-8 w-8 bg-primary/10">
                    <AvatarFallback
                        class="bg-transparent text-xs font-semibold text-primary"
                    >
                        {{ initial }}
                    </AvatarFallback>
                </Avatar>
                <span
                    class="hidden text-sm font-medium text-foreground sm:block"
                    >{{ userName }}</span
                >
            </div>
            <div class="h-5 w-px bg-border" />
            <Link
                :href="destroy.url()"
                method="post"
                as="button"
                class="group rounded-lg p-2 transition-colors duration-150 hover:bg-danger/10"
                title="Cerrar sesión"
            >
                <svg
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.5"
                    stroke="currentColor"
                    class="h-4.5 w-4.5 text-foreground opacity-60 transition-colors group-hover:text-danger group-hover:opacity-100"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"
                    />
                </svg>
            </Link>
        </div>
    </header>
</template>
