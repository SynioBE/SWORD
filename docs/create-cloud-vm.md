# Creating Cloud Virtual Machines with Artisan

This document outlines how to use the `./vendor/bin/sail artisan sword:<provier>:create` command (or [sword](../sword) 
utility) to provision virtual machines on cloud providers like DigitalOcean and Hetzner. 

To prevent API keys from being exposed in environment variables directly or committed to version control, 
we use Docker Compose secrets and the `secrets/` directory.

1.  Inside `secrets/`, create individual files for each API key. These files should contain *only* the raw API key.
    *   For DigitalOcean: `secrets/do-api.txt`
    *   For Hetzner Cloud: `secrets/hetzner-api.txt`

    ```bash
    # Example:
    echo "your_digitalocean_api_token" > secrets/do-api.txt
    echo "your_hetzner_api_token" > secrets/hetzner-api.txt
    ```
2.  Copy the provided template:
    ```bash
    cp compose.override-dist.yaml compose.override.yaml
    ```

3.  Restart Sail to ensure the new secrets are loaded:
    ```bash
    ./vendor/bin/sail down
    ./vendor/bin/sail up -d
    ```

## DigitalOcean Example

To create a DigitalOcean Droplet:

```bash
./vendor/bin/sail artisan sword:digitalocean:create
    --name="my-app-server" 
    --region="nyc1" 
    --size="s-1vcpu-1gb" 
    --image="ubuntu-24-04-x64" 
    --ssh-keys="your_ssh_key_fingerprint_or_id"
```

## Hetzner Cloud Example

To create a Hetzner Cloud Server:

```bash
./vendor/bin/sail artisan sword:hetzner:create
    --name="my-hetzner-server" 
    --location="fsn1" 
    --type="cx11" 
    --image="ubuntu-24.04" 
    --ssh-keys="your_ssh_key_id"
```

Remember to replace placeholder values with your actual desired configurations.
