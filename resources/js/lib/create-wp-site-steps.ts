export const STEP_LABELS: Record<string, string> = {
    create_database: 'Creating MySQL database & user',
    docker_setup: 'Building containers & writing config',
    install_wordpress: 'Installing WordPress',
    installed: 'Finalizing & restarting services',
};

export const STEP_KEYS = Object.keys(STEP_LABELS);

export function stepLabel(key: string | null): string {
    if (!key) {
return 'Installing…';
}

    return STEP_LABELS[key] ?? key;
}
