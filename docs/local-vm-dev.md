# Local Ubuntu Server 24 LTS VM (KVM or VirtualBox)

This guide covers creating a local **Ubuntu Server 24.04 LTS** virtual machine for development tasks such as running provisioning scripts that must be fetched over HTTP from your **host** machine (for example, replacing `localhost` in URLs with the host’s LAN IP, e.g. `172.26.32.79`).

---

## What you need

- A 64-bit x86 host with hardware virtualization enabled in firmware (Intel VT-x / AMD-V).
- Roughly **2 GB RAM** minimum for the guest (4 GB is more comfortable), **20+ GB** disk, and a few GB free on the host for the ISO and disk image.
- Administrator rights on the host to install hypervisor packages or VirtualBox.

---

## Download the Ubuntu Server ISO

Use the official release directory (verify the exact `.iso` filename on the page; minor point releases change over time):

- **Ubuntu Server 24.04 LTS (amd64):** [releases.ubuntu.com — 24.04](https://releases.ubuntu.com/24.04/)

Pick the **live server** installer image (e.g. `ubuntu-24.04.*-live-server-amd64.iso`). Check the published checksums on the same page and verify the download if you rely on this VM for anything sensitive.

> [!TIP]
> Prefer HTTPS downloads from `releases.ubuntu.com` or an official mirror. Avoid third-party “repackaged” ISOs.

---

## Option A: KVM / libvirt (native Linux)

### Install the stack (Debian/Ubuntu-style host)

```bash
sudo apt update
sudo apt install qemu-kvm libvirt-daemon-system libvirt-clients bridge-utils virt-manager virtinst
sudo usermod -aG libvirt,kvm "$USER"
```

Log out and back in (or reboot) so group membership applies.

### Create the VM (GUI: virt-manager)

1. Start **Virtual Machine Manager** (`virt-manager`).
2. **File → New Virtual Machine** → **Local install media** → **Forward**.
3. **Browse** to your Ubuntu Server ISO → **Forward**.
4. Choose **RAM** and **CPUs**, set **disk size** (e.g. 32 GB) → **Forward**.
5. Name the VM (e.g. `ubuntu-server-24`) → **Finish**.
6. Complete the Ubuntu installer (keyboard, storage, user, SSH server — **install OpenSSH** for easy access).

### Create the VM (CLI: `virt-install`)

Adjust paths, RAM, CPUs, and disk size as needed:

```bash
sudo virt-install \
  --name ubuntu-server-24 \
  --memory 4096 \
  --vcpus 2 \
  --disk path=/var/lib/libvirt/images/ubuntu-server-24.qcow2,size=32 \
  --cdrom /path/to/ubuntu-24.04.*-live-server-amd64.iso \
  --os-variant ubuntu24.04 \
  --network network=default \
  --graphics spice \
  --console pty,target_type=serial
```

Follow the text or graphical installer; enable **OpenSSH server** when prompted.

### Default networking (NAT)

The default **virbr0** NAT network usually gives the guest outbound internet and a route to the host on the bridge address (often `192.168.122.1` from the guest’s perspective, depending on your libvirt config). The **host’s LAN IP** (e.g. `172.26.32.79`) is only reachable from the guest if routing/firewall rules allow it — often they do on a typical home LAN.

> [!IMPORTANT]
> **`localhost` inside the VM is the VM itself**, not your Laravel or web server on the physical host. Use the **host machine’s IP on your LAN** (or the documented VirtualBox/KVM host address below) in URLs such as `http://<host-ip>/servers/...`.

> [!WARNING]
> Your app must **listen on an interface the VM can reach** (typically `0.0.0.0` or the host’s LAN IP), not only `127.0.0.1`. For example, `php artisan serve` defaults to binding in a way that may not accept connections from other machines — use `--host=0.0.0.0` (and open the port in the host firewall) when serving from the host to the VM.

---

## Option B: VirtualBox

### Install VirtualBox

Install **VirtualBox** from your distribution’s packages or [Oracle’s VirtualBox download page](https://www.virtualbox.org/wiki/Downloads) (follow your OS instructions).

> [!NOTE]
> On Linux hosts, installing the **VirtualBox Extension Pack** is optional for basic networking; it is mainly needed for USB 3, RDP, and some other features.

### Create the VM

1. **New** → name (e.g. `Ubuntu Server 24`), type **Linux**, version **Ubuntu (64-bit)**.
2. Assign **RAM** (e.g. 4096 MB) and **create a virtual disk** (VDI, dynamically allocated, e.g. 32 GB).
3. **Settings → Storage**: empty optical drive → **choose disk file** → select your Ubuntu Server ISO.
4. **Settings → System → Processor**: enable **PAE/NX** if needed; 2 vCPUs is reasonable.
5. **Start** the VM and run through the Ubuntu Server installer; install **OpenSSH server**.

### Networking modes (short)

| Mode | Typical use |
| --- | --- |
| **NAT** (default) | Guest reaches the internet; **from the guest, the host is often reachable at `10.0.2.2`** (VirtualBox’s fixed address for the host on the NAT network). |
| **Bridged** | Guest gets an IP on the **same LAN** as the host — use the host’s **real LAN IP** (e.g. `172.26.32.79`) in URLs. |
| **Host-only** | Guest and host on a private subnet only; configure host IP on the host-only adapter and use that in URLs. |

> [!IMPORTANT]
> With **NAT**, using your host’s LAN IP (e.g. `172.26.32.79`) in the guest **may or may not work** depending on hairpin NAT and routing. If `wget` to that IP fails, try **`http://10.0.2.2/...`** from the guest (host port 80 must be listening and reachable), or switch the VM NIC to **Bridged Adapter** and use the host’s LAN IP.

> [!WARNING]
> **Bridged** mode exposes the VM on the LAN like a physical machine. Use a strong password/key for SSH and keep the image updated.

---

## Simple validation

Run these **on the guest** (SSH or console) after the VM boots.

### 1. Basic connectivity

```bash
ip -br a
ping -c 3 1.1.1.1
```

You should see a guest IP and successful pings (outbound internet).

### 2. Reach the host

Replace `<HOST_IP>` with your host’s address (LAN IP for bridged/KVM typical setups, or `10.0.2.2` for VirtualBox NAT if applicable):

```bash
ping -c 3 <HOST_IP>
```

### 3. HTTP to the host (after your server is running)

```bash
curl -sS -o /dev/null -w "%{http_code}\n" "http://<HOST_IP>/"
```

You should get an HTTP status code (e.g. `200`, `302`, or `404` from the app — **not** `000` / connection refused).

> [!CAUTION]
> If `curl` or `wget` fails with “Connection refused”, check: (1) the app is running on the host, (2) it listens on `0.0.0.0` or the host’s LAN IP, (3) **host firewall** allows TCP from the guest to the port (e.g. `80`), (4) you used the correct IP for your hypervisor/network mode.

---

## Example: provisioning script over HTTP from the host

This project may use a command like the following **inside the VM**. Substitute **`localhost`** with your **host IP** (e.g. `172.26.32.79`) so the request hits the machine where the app is served, not the VM itself:

```bash
wget -qO sword-provision.sh "http://172.26.32.79/servers/1/scripts/provision?token=wqtTxmrDByGZmZEVl2MjMSP8ZCbal31nT31OH4SyDm3SJnXn3WyRq86QS5qvBc2V" \
  && sudo bash sword-provision.sh 2>&1 | tee sword-provision.log
```

> [!WARNING]
> **Secrets in URLs** appear in shell history, process listings, and logs. Prefer short-lived tokens, rotation, and restricted networks for real environments.

---

## Quick troubleshooting

| Symptom | Things to check |
| --- | --- |
| Guest has no IP | NIC attached? Correct network mode? `sudo dhclient -v` (if needed). |
| Can ping internet but not host | Firewall on host; wrong IP (`10.0.2.2` vs LAN IP); app bound only to `127.0.0.1`. |
| HTTP timeout | Host firewall; wrong port; VPN split-tunneling changing routes. |

---

## Further reading

- [GitHub: Basic writing and formatting syntax](https://docs.github.com/en/get-started/writing-on-github/getting-started-with-writing-and-formatting-on-github/basic-writing-and-formatting-syntax) (Markdown used in this file, including [alerts](https://docs.github.com/en/get-started/writing-on-github/getting-started-with-writing-and-formatting-on-github/basic-writing-and-formatting-syntax#alerts))
- [Ubuntu Server documentation](https://ubuntu.com/server/docs)
