# sds3000x-HD_flex-wifi

WiFi addon for [Siglent SDS3000X-HD](https://www.siglenteu.com/digital-oscilloscopes/sds3000x-hd-digital-storage-oscilloscope/) oscilloscopes. Adds WiFi connectivity via a USB dongle using the MT7610U chipset (e.g. Panda Wireless PAU0A AC600).

## Download

- **[wifi_addon.zip](https://github.com/folays/sds3000x-HD_flex-wifi/releases/latest/download/wifi_addon.zip)** — built by GitHub Actions CI (not locally). Three ways to verify:
  1. `sha256sum wifi_addon.zip` and compare with the attestation digest: go to [Actions](https://github.com/folays/sds3000x-HD_flex-wifi/actions) → click the latest workflow run → click "Attestation Created" → scroll to the bottom, the SHA256 is under "Subject digest" next to `wifi_addon.zip` (don't confuse with the `wifi_addon_artifact` shown on the run page — that's a wrapper ZIP with a different hash)
  2. `gh attestation verify wifi_addon.zip --repo folays/sds3000x-HD_flex-wifi`
  3. Rebuild from source: `docker build` or `podman build`, compare SHA256

- **[FLEX-WIFI.ADS](https://github.com/folays/sds3000x-HD_flex-wifi/releases/latest/download/FLEX-WIFI.ADS)** — the ZIP above wrapped in Siglent's .ADS firmware format, ready to upload to the scope. This file is built offline (not by CI). If you can decode .ADS files, you can extract the ZIP inside and verify its SHA256 matches the CI-built one above.

## Why MT7610U?

The SDS3000X-HD runs Linux 5.10 (aarch64). This severely limits the choice of USB WiFi chipsets:

- **RTL8192CU** (Realtek): driver exists in 5.10 (`rtl8xxxu`), but terrible radio performance and range. Unusable in practice.
- **RTL8811CU** (Realtek): no mainline driver until kernel 6.12 (`rtw88`). Many "MT7610U" dongles have been silently switched to this chipset by manufacturers — they look identical but won't work on 5.10. For example, the Edimax EW-7811ULC is advertised as MT7610U but now ships with an RTL8811CU (USB ID `7392:c811`).
- **MT7921AU** (MediaTek, WiFi 6E): excellent chip, but the driver requires backporting from kernel 5.19+. Works but needs a large patchset and bulky dongles (MIMO antennas).
- **MT7610U** (MediaTek, AC600): driver `mt76x0u` is **native in kernel 5.10**, no backport needed. Dual-band 2.4/5 GHz, good range, compact dongle form factor.

MT7610U is the only chipset that is both natively supported in kernel 5.10 and available in a compact, well-performing dongle. The Panda Wireless PAU0A AC600 is one of the few dongles still guaranteed to ship with a genuine MT7610U — most competitors have silently swapped to Realtek chips.

See also:
- [USB WiFi chipsets — what to look for](https://github.com/morrownr/USB-WiFi/blob/main/home/USB_WiFi_Chipsets.md)
- [USB WiFi adapters supported with Linux in-kernel drivers](https://github.com/morrownr/USB-WiFi/blob/main/home/USB_WiFi_Adapters_that_are_supported_with_Linux_in-kernel_drivers.md)
- [Linux Mint forum — recommended WiFi adapters](https://forums.linuxmint.com/viewtopic.php?t=405457)

## Compatible dongles

Any USB WiFi dongle supported by the Linux `mt76x0u` driver (kernel 5.10):

| Dongle | USB ID | Chipset |
|--------|--------|---------|
| Panda Wireless PAU0A AC600 | `0e8d:7610` | MT7610U |
| Linksys AE6000 | `13b1:003e` | MT7610U |
| Asus USB-AC51 | `0b05:17d1` | MT7610U |
| Asus USB-AC50 | `0b05:17db` | MT7610U |
| D-Link DWA-171 rev B1 | `2001:3d02` | MT7610U |
| TP-Link TL-WDN5200 | `148f:761a` | MT7610U |
| Zyxel NWD6505 | `0586:3425` | MT7610U |
| AVM FRITZ!WLAN AC 430 | `057c:8502` | MT7610U |
| Sitecom WLA-3100 | `0df6:0075` | MT7610U |
| TRENDnet TEW-806UBH | `20f4:806b` | MT7610U |
| ... and others | | |

The full list is auto-generated from the driver's device table at build time (`modinfo_aliases.txt`).

## How it works

- **S99zwifi**: init.d script on the scope's rootfs. At boot, checks if a compatible USB dongle is plugged (via modalias matching). If not, does nothing — the scope boots normally.
- **setup.sh**: loads 6 kernel modules (mt76 driver stack), creates a symlink for the web config page, and connects to WiFi if a saved configuration exists.
- **flex-wifi.php**: web-based WiFi configuration page accessible at `http://<scope_ip>/web_img/flex-wifi.php`. Scan for networks, connect, view status. All AJAX, no page reloads.
- **wpa_action.sh**: event-driven DHCP — `wpa_cli -a` calls `udhcpc` on CONNECTED, flushes IP on DISCONNECTED. No sleeps, no polling.

WiFi configuration is stored in `/usr/bin/siglent/usr/flex-wifi.conf` (writable partition, outside the addon directory, survives addon reinstall).

## Safety

- If the dongle is unplugged, nothing executes at boot.
- If a firmware update changes the kernel version, modules fail to load gracefully (vermagic mismatch). The scope boots normally.
- The addon never modifies the scope's application or FPGA. Only adds an init.d script and a firmware symlink to the rootfs.
- `update.sh` uses `set -e` — if any step fails, the script stops. The scope's boot process continues regardless.

## Building

### Prerequisites

- Docker or Podman

### Build the ZIP

```bash
# With Podman
./build.sh

# With Docker
docker build -t flex-wifi-builder .
CID=$(docker create flex-wifi-builder)
docker cp "$CID:/wifi_addon.zip" wifi_addon.zip
docker rm "$CID"
sha256sum wifi_addon.zip
```

The build downloads kernel 5.10 source from kernel.org, verifies the scope's kernel config by SHA256 hash, cross-compiles the mt76x0u modules, and packages everything with wpa_supplicant and libraries from Ubuntu 20.04 arm64 official packages.

### Create the .ADS firmware package

The ZIP must be wrapped into a .ADS file for the scope to accept it. Sorry, there is no public tool for this. Pre-built .ADS files are provided in the [releases](https://github.com/folays/sds3000x-HD_flex-wifi/releases).

### Verify a build

The GitHub Actions CI builds the ZIP on GitHub's infrastructure with [build provenance attestation](https://docs.github.com/en/actions/security-for-github-actions/using-artifact-attestations/using-artifact-attestations-to-establish-provenance-for-builds). To verify that a ZIP was built by the CI:

```bash
gh attestation verify wifi_addon.zip --repo folays/sds3000x-HD_flex-wifi
```

Or rebuild from source and compare SHA256.

## What's inside the ZIP

| Category | Size | Files |
|----------|------|-------|
| Kernel modules (.ko) | 17.5 MB | mt76, mt76-usb, mt76x02-lib, mt76x02-usb, mt76x0-common, mt76x0u |
| Shared libraries | ~3 MB | libnl-3, libssl, libcrypto, libdbus, libsystemd, etc. (from Ubuntu 20.04 arm64 debs) |
| Binaries | 3.5 MB | wpa_supplicant, wpa_cli, wpa_passphrase, openssl (from Ubuntu 20.04 arm64 debs) |
| Firmware | 80 KB | mt7610u.bin (from linux-firmware) |
| Scripts | ~20 KB | update.sh, setup.sh, S99zwifi, wpa_action.sh, flex-wifi.php |

## Provenance

All binaries come from verifiable sources:

- **Kernel modules**: cross-compiled from [kernel.org](https://cdn.kernel.org/pub/linux/kernel/v5.x/linux-5.10.tar.xz) vanilla 5.10 source with the scope's kernel config (SHA256-verified before build)
- **wpa_supplicant, wpa_cli, wpa_passphrase**: extracted from [Ubuntu 20.04 arm64 official .deb](http://ports.ubuntu.com/pool/main/w/wpa/)
- **openssl**: extracted from [Ubuntu 20.04 arm64 official .deb](http://ports.ubuntu.com/pool/main/o/openssl/)
- **Shared libraries**: extracted from Ubuntu 20.04 arm64 official .debs (libnl3, libssl1.1, libdbus-1-3, libsystemd0, etc.)
- **mt7610u.bin firmware**: from [linux-firmware.git](https://git.kernel.org/pub/scm/linux/kernel/git/firmware/linux-firmware.git/)
- **Scripts**: in this repository

## First install

1. Build or download `wifi_addon.zip`
2. Encode it into a `.ADS` file
3. Plug in the USB WiFi dongle
4. Upload the `.ADS` via the scope's web interface (Device Update)
5. The scope reboots. The addon is installed, modules are loaded.
6. Open `http://<scope_ip>/web_img/flex-wifi.php`
7. Scan, select your network, enter the passphrase, connect
8. WiFi will auto-reconnect on subsequent reboots

**Note**: hotplug is intentionally not supported. Only the S99zwifi init script attempts to detect the dongle and load modules, and it runs once at boot. This is a deliberate choice to minimize complexity and eliminate any risk of bricking the scope. If you plug the dongle after boot, simply reboot.

## License

Kernel modules: GPL v2 (Linux kernel)
Scripts: MIT
