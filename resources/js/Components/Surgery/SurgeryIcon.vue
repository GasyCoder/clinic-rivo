<script setup>
import { computed } from 'vue';
import {
    Activity,
    ArrowLeft,
    ArrowRight,
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronDown,
    CircleAlert,
    Clock3,
    CreditCard,
    DoorOpen,
    ExternalLink,
    Eye,
    FileText,
    Info,
    LogIn,
    LogOut,
    Pencil,
    Plus,
    Save,
    ShieldCheck,
    Trash2,
    UserCheck,
    Users,
    X,
} from 'lucide-vue-next';

const props = defineProps({
    name: { type: String, required: true },
});

const icons = {
    activity: Activity,
    'alert-circle': CircleAlert,
    'arrow-left': ArrowLeft,
    'arrow-right': ArrowRight,
    calendar: CalendarDays,
    cards: CreditCard,
    check: Check,
    'check-circle': CheckCircle2,
    'chevron-down': ChevronDown,
    clock: Clock3,
    cross: X,
    edit: Pencil,
    external: ExternalLink,
    eye: Eye,
    'file-text': FileText,
    info: Info,
    lock: ShieldCheck,
    masks: DoorOpen,
    plus: Plus,
    save: Save,
    'shield-check': ShieldCheck,
    signin: LogIn,
    signout: LogOut,
    trash: Trash2,
    'user-check': UserCheck,
    users: Users,
};

const icon = computed(() => icons[props.name] ?? FileText);
</script>

<template>
    <component :is="icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
</template>
