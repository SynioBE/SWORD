The open-source, self-hosted alternative to FlyWP and SpinupWP:
Slash your hosting cost with SWORD.
We are building a lightweight, high-performance control panel to deploy and manage WordPress sites using Docker.

It’s designed for developers and agencies who want the speed and isolation of containerized hosting without paying monthly fees for SaaS management panels. Also for developers and agencies who want more control over their infrastructure.

We start from a fresh Laravel 12 with Inertia and Vue.js. Please create a multi-tenant dashboard where a tenant has 2 tabs: Servers & Sites. We will focus on just the Servers part for now.

When adding a server, a server is created in the DB and a Bash file is generated to set up a server. Then a simple script is given containing a signature secured URL like this:

(example from competitor) wget -qO fly-provision.sh "https://app.flywp.com/provision-server/7353?token=QksiuzcZQ5t6Ee2wsAmFDpjLa2qosxeVkQWMpiKd" && sudo bash fly-provision.sh 2>&1 | tee log-provision.log

The user has to run this on a fresh Ubuntu 24.04 install. The bash script that will be downloaded will do the following:

== Server provisioning script ==

- Create server in app DB
- Check if root
- Ping app after every step via curl
- Wait until apt is ready
- IPv4 preference
- Setup swap disk and config
- Upgrade OS packages
- Install extra OS packages
- Ensure cron is running
- Install Docker
  - Add GPG key
  - Add Docker repo
  - Update packages
  - Install packages
  - Create/update Docker config
  - Restart Docker
- SSH setup
  - Disable passwordless auth
  - Create SSH key
  - Restart SSH
  - Set hostname
  - Set timezone
  - Create root SSH directory
- Create app folders (.sword ?)
- Setup user (sword ?)
  - Add user
  - Create app folders (.sword ?)
  - Add docker group
  - Add to sudo and docker groups
  - Setup Bash
  - Set Sudo password
  - Append generated SSH key to authorized keys (also for root)
  - Copy source control keys into known hosts files
  - Configure Git settings
  - Chown & chmod home dir + chmod ssh key file
- Security hardening
  - UFW allow 22, 80, 443
  - Enable UFW
- Setup unattended security upgrades
  - Install package
  - Configure unattended upgrades
- Auto-start Docker

== INIT ==

- Shared MySQL container
- Shared Redis container (later: per site?)
- Docker compose
  - Proxy (would prefer Traefik here)
  - MySQL
  - Redis
  - Ofelia

There should be a live preview in the dashboard when creating a server.
This is handled by a function in bash like this example

(competitor)
function provisionPing {
    curl -s --insecure -d "status=creating&step=$1" -X POST "https://app.flywp.com/callback/servers/7353/provision?signature=9be527a3830054682536601660fbba13cbf7359318d8f4f581950d80f9f182e6"
}

After the server has been set up, it will be visible in the UI.

====

Now create the "create site" functionality which asks for one of the servers, and then installs a WP site, roughly like this:

- Create site in app DB
- Create user if needed (same as server provisioning)
- Create directories and files
- Setup Nginx config + firewall rules
- Create Docker Compose file
  - PHP (custom)
  - Nginx
  - Networks
- Spin up containers & restart Ofelia
- Install WordPress
  - Fix permissions?
  - Download WordPress via WP CLI
  - Create MySQL DB on main MySQL container
  - Create Redis cache on main Redis container (or separate container?)
  - Create WP config
  - Move WP config up
  - Install WordPress via WP CLI
  - Delete Hello Dolly plugin
  - Install & enable Redis plugin
  - Future: Install Sword plugin
  - Install & enable FastCGI caching support
- Future: Apply blueprint
