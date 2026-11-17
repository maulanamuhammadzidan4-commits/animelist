# AniList

A self-hosted anime library for a local network. Browse your collection, select seasons, and stream supported video files from a web browser without relying on an external streaming service.

> This guide focuses on Linux, especially Arch Linux. The project uses PHP and Apache HTTP Server.

## Features

- Browse anime grouped into **Ongoing** and **Completed** collections.
- Search anime titles.
- Read anime folders recursively and list episodes inside season/part subfolders.
- Show a season/part selection page when an anime contains subfolders.
- Use `poster.png` from an anime's folder for its card and detail-page artwork when available.
- Play supported video formats through the browser's HTML5 video player.
- Automatically continue to the next episode when playback ends, when one is available.
- Access the library from other devices connected to the same local Wi-Fi/LAN.

## Project layout

The web root should contain the application files and image assets:

```text
animelist/
├── index.php
├── logic.php
├── style.css
├── logo.png
├── bg.png
└── anime -> /path/to/your/Anime
```

The `anime` entry can be a symbolic link to your media collection. The collection is expected to use this structure:

```text
Anime/
├── end/
│   ├── Anime Title/
│   │   ├── poster.png          # optional
│   │   ├── Episode 01.mp4
│   │   └── Season 2/
│   │       ├── Episode 01.mkv
│   │       └── Episode 02.mkv
│   └── Another Anime/
└── ongoing/
    └── Anime Title/
        ├── poster.png          # optional
        └── Episode 01.mp4
```

The application scans the `end` and `ongoing` directories. Keep the actual media files in your own collection; they are not part of this repository.

## Requirements

- Linux
- Apache HTTP Server
- PHP with the extensions required by the project (at minimum, commonly `mbstring` for multibyte title searching)
- A modern browser on the client device
- All devices must be connected to the same local network to use the LAN address

Video playback depends on the browser and the codecs inside each file. MP4 with H.264 video and AAC audio is broadly supported. MKV and AVI are containers, not guarantees of browser compatibility; playback may fail even when the file is readable by Apache.

## Installation — Arch Linux

### 1. Install Apache and PHP

Update the system and install the web server and PHP packages:

```bash
sudo pacman -Syu
sudo pacman -S apache php php-apache
```

Enable and start Apache:

```bash
sudo systemctl enable --now httpd
```

Check that it is running:

```bash
systemctl status httpd
```

### 2. Configure Apache for PHP

Open the Apache configuration:

```bash
sudo nano /etc/httpd/conf/httpd.conf
```

Make sure the PHP module is loaded and its configuration is included. On Arch Linux, the `php-apache` package provides the module configuration. Ensure these directives are present and active (not commented out), following the package's recommended configuration:

```apache
LoadModule mpm_prefork_module modules/mod_mpm_prefork.so
LoadModule php_module modules/libphp.so
AddHandler php-script .php
Include conf/extra/php_module.conf
```

Apache must use the `prefork` MPM with `mod_php`; do not load another MPM such as `event` or `worker` at the same time.

Also ensure PHP is the first directory index:

```apache
DirectoryIndex index.php index.html
```

Validate the configuration and restart Apache:

```bash
sudo apachectl configtest
sudo systemctl restart httpd
```

Expected config-test result:

```text
Syntax OK
```

> Configuration can differ if you use PHP-FPM instead of `mod_php`. This guide describes the `php-apache` / `mod_php` setup.

### 3. Put the project in Apache's web directory

Clone your repository into `/srv/http/animelist` (replace the URL with your repository URL):

```bash
sudo mkdir -p /srv/http/animelist
sudo chown "$USER":"$USER" /srv/http/animelist
git clone YOUR_REPOSITORY_URL /srv/http/animelist
```

If you already cloned the repository elsewhere, copy or move the project files into `/srv/http/animelist` instead.

The final directory should include `index.php`, `logic.php`, `style.css`, `logo.png`, and `bg.png`.

### 4. Connect your anime collection

For example, if your media collection is stored at `/home/your-user/Anime`:

```bash
sudo ln -s /home/your-user/Anime /srv/http/animelist/anime
```

If an `anime` entry already exists, inspect it before replacing it:

```bash
ls -ld /srv/http/animelist/anime
```

Do not create a second link over an existing directory or link.

### 5. Allow Apache to read the media

Apache runs as the `http` user on Arch Linux. It needs:
- execute (`x`) permission on every parent directory in the path;
- read (`r`) permission on the anime folders and video files;
- execute (`x`) permission on directories so it can enter and scan them.

Check the path permissions:

```bash
namei -l /home/your-user/Anime
```

Do not make your entire home directory world-readable. Prefer granting the Apache service access only to the media directory, for example with a dedicated shared group or carefully scoped ACLs.

Example using ACLs (replace `your-user` with your Linux username):

```bash
sudo pacman -S acl
sudo setfacl -m u:http:--x /home/your-user
sudo setfacl -R -m u:http:rX /home/your-user/Anime
sudo setfacl -R -d -m u:http:rX /home/your-user/Anime
```

The default ACL helps newly created files and directories inherit access. Review your permissions before using this on a personal media directory.

### 6. Account for systemd service sandboxing

Some Linux distributions or local systemd overrides restrict Apache's access to paths under `/home`. If Apache can serve the website but reports that the `anime` link or its target does not exist, check the service restrictions:

```bash
systemctl cat httpd
```

If `ProtectHome=yes` is active, Apache may be prevented from accessing `/home` even when normal file permissions allow it.

One approach is to grant the service read-only access to the media directory with a systemd drop-in:

```bash
sudo systemctl edit httpd
```

Add:

```ini
[Service]
BindReadOnlyPaths=/home/your-user/Anime
```

Save, then reload systemd and restart Apache:

```bash
sudo systemctl daemon-reload
sudo systemctl restart httpd
```

Confirm the effective service configuration:

```bash
systemctl show httpd -p BindReadOnlyPaths -p ProtectHome
```

Keep the media mount read-only for the web server. Do not disable all service hardening just to make the media directory visible.

### 7. Set basic file permissions

The web application files should be readable by Apache. For example:

```bash
sudo find /srv/http/animelist -maxdepth 1 -type f \
  -exec chmod 644 {} \;
sudo chmod 755 /srv/http/animelist
```

Avoid recursively changing ownership or permissions of the media collection unless you understand the effect on your own user account and other applications.

### 8. Open the website

On the server itself:

```text
http://localhost/animelist/
```

To access it from another device on the same Wi-Fi, find the server's LAN IP:

```bash
ip -brief address
```

Then open:

```text
http://SERVER_LAN_IP/animelist/
```

For example, if the server's address is `192.168.1.20`:

```text
http://192.168.1.20/animelist/
```

If the page works locally but not from another device, check that both devices are on the same network, Apache is listening on the network interface, and the firewall allows inbound HTTP traffic on TCP port `80`. Do not expose this personal media server directly to the public internet without adding appropriate security controls.

## Other Linux distributions

The overall setup is the same on other distributions:

1. Install Apache and PHP using the distribution's package manager.
2. Configure Apache to execute PHP (using `mod_php` or PHP-FPM).
3. Place the project under the configured document root.
4. Link the `anime` directory to your media collection.
5. Grant the web-server account read-only access to the media.
6. Start/enable the Apache service and test locally.

Package names, Apache configuration paths, service usernames, and service-hardening defaults vary by distribution. For example, Debian-based systems commonly use the `www-data` account and `/var/www/html` as a default document root, unlike Arch Linux's `http` account and `/srv/http`.

## Troubleshooting

### The anime list is empty

Check that the link exists and points to the expected location:

```bash
ls -ld /srv/http/animelist/anime
readlink -f /srv/http/animelist/anime
```

Check that the Apache user can read the collection:

```bash
sudo -u http find /srv/http/animelist/anime -maxdepth 2 -type d
```

If this fails, inspect parent-directory permissions with `namei -l` and check `ProtectHome` / `BindReadOnlyPaths` in the Apache systemd service.

### The page shows a PHP error or downloads the PHP source

Check Apache's PHP module configuration and logs:

```bash
sudo apachectl configtest
sudo journalctl -u httpd -b --no-pager
```

Make sure Apache is configured to handle `.php` files and that only one MPM is loaded.

### A video does not play

The browser may not support the file's container or codec. Test with a known-compatible MP4 file. A file extension alone does not identify the codecs inside a video.

### Other devices cannot connect

Check the server's LAN address and firewall rules. The server and client must be able to communicate over the local network. Use the server's current LAN IP, which may change when the router renews DHCP leases.

## Security notes

- This project is intended for a trusted local network.
- Keep the anime media directory read-only to Apache.
- Do not run Apache as root.
- Do not expose the server to the public internet without authentication, HTTPS, firewall restrictions, and a security review.
- Do not store private or sensitive files inside the public web directory.
- The repository contains the application; keep your personal video collection outside Git and link it into the project.

## License

Add the license you choose for this project. Only include media or artwork in the repository when you have the right to distribute it.
