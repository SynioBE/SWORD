export const STEP_LABELS: Record<string, string> = {
    configuring_swap: 'Configuring swap space',
    os_upgrade: 'Upgrading OS packages',
    install_packages: 'Installing required packages',
    docker_setup: 'Installing & configuring Docker',
    ssh_setup: 'Configuring SSH & authorizing keys',
    user_setup: 'Creating SWORD user',
    firewall_setup: 'Configuring firewall',
    security_updates: 'Enabling automatic security updates',
    provisioned: 'Launching services & finalizing',
};

export const STEP_KEYS = Object.keys(STEP_LABELS);

export function stepLabel(key: string | null): string {
    if (!key) {
return 'Provisioning…';
}

    return STEP_LABELS[key] ?? key;
}
