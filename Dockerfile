FROM --platform=linux/amd64 ubuntu:20.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y \
    gcc-8-aarch64-linux-gnu \
    make bc flex bison \
    libssl-dev libelf-dev \
    wget xz-utils kmod zip \
    && rm -rf /var/lib/apt/lists/*

RUN ln -sf aarch64-linux-gnu-gcc-8 /usr/bin/aarch64-linux-gnu-gcc

# Kernel 5.10.0 source
RUN wget -q https://cdn.kernel.org/pub/linux/kernel/v5.x/linux-5.10.tar.xz -O /tmp/linux-5.10.tar.xz \
    && tar xf /tmp/linux-5.10.tar.xz -C /opt \
    && rm /tmp/linux-5.10.tar.xz

# Scope kernel config (from /proc/config.gz, GPL)
COPY kernel-config.gz /tmp/kernel-config.gz
RUN gunzip -c /tmp/kernel-config.gz > /opt/linux-5.10/.config

# Verify config matches known scope config BEFORE any modification
RUN HASH=$(sha256sum /opt/linux-5.10/.config | cut -d' ' -f1) \
    && EXPECTED="efc2fd4642d8dcd0ada0cb600281427f1e1dde5460ce9c499dde7cbcee08c82d" \
    && echo "Config SHA256: $HASH" \
    && echo "Expected:      $EXPECTED" \
    && [ "$HASH" = "$EXPECTED" ] || (echo "MISMATCH — config has been tampered with" && false)

# EXTRAVERSION must match scope's vermagic: "5.10.0+ SMP mod_unload aarch64"
RUN sed -i 's/^EXTRAVERSION =$/EXTRAVERSION = +/' /opt/linux-5.10/Makefile

# Enable mt76x0u as module
RUN cd /opt/linux-5.10 && \
    echo "CONFIG_MT76_CORE=m" >> .config && \
    echo "CONFIG_MT76_USB=m" >> .config && \
    echo "CONFIG_MT76x02_LIB=m" >> .config && \
    echo "CONFIG_MT76x02_USB=m" >> .config && \
    echo "CONFIG_MT76x0_COMMON=m" >> .config && \
    echo "CONFIG_MT76x0U=m" >> .config

RUN cd /opt/linux-5.10 && \
    make ARCH=arm64 CROSS_COMPILE=aarch64-linux-gnu- olddefconfig && \
    make ARCH=arm64 CROSS_COMPILE=aarch64-linux-gnu- modules_prepare

# Build mt76x0u modules
RUN cd /opt/linux-5.10 && \
    make ARCH=arm64 CROSS_COMPILE=aarch64-linux-gnu- M=drivers/net/wireless/mediatek/mt76 modules

# Download mt7610u firmware from linux-firmware
RUN mkdir -p /output/libs && \
    wget -q https://git.kernel.org/pub/scm/linux/kernel/git/firmware/linux-firmware.git/plain/mediatek/mt7610u.bin -O /output/mt7610u.bin

# Download wpa_supplicant + libs from Ubuntu 20.04 arm64 official debs
RUN mkdir -p /tmp/debs && cd /tmp/debs && \
    wget -q http://ports.ubuntu.com/pool/main/w/wpa/wpasupplicant_2.9-1ubuntu4_arm64.deb && \
    wget -q http://ports.ubuntu.com/pool/main/libn/libnl3/libnl-3-200_3.4.0-1_arm64.deb && \
    wget -q http://ports.ubuntu.com/pool/main/libn/libnl3/libnl-genl-3-200_3.4.0-1_arm64.deb && \
    wget -q http://ports.ubuntu.com/pool/main/libn/libnl3/libnl-route-3-200_3.4.0-1_arm64.deb && \
    wget -q http://ports.ubuntu.com/pool/main/o/openssl/libssl1.1_1.1.1f-1ubuntu2_arm64.deb && \
    wget -q http://ports.ubuntu.com/pool/main/d/dbus/libdbus-1-3_1.12.16-2ubuntu2_arm64.deb && \
    wget -q http://ports.ubuntu.com/pool/main/p/pcsc-lite/libpcsclite1_1.8.26-3_arm64.deb && \
    wget -q http://ports.ubuntu.com/pool/main/s/systemd/libsystemd0_245.4-4ubuntu3_arm64.deb && \
    wget -q http://ports.ubuntu.com/pool/main/x/xz-utils/liblzma5_5.2.4-1ubuntu1.1_arm64.deb && \
    wget -q http://ports.ubuntu.com/pool/main/l/lz4/liblz4-1_1.9.2-2_arm64.deb && \
    wget -q http://ports.ubuntu.com/pool/main/libg/libgcrypt20/libgcrypt20_1.8.5-5ubuntu1_arm64.deb && \
    wget -q http://ports.ubuntu.com/pool/main/libg/libgpg-error/libgpg-error0_1.37-1_arm64.deb && \
    wget -q http://ports.ubuntu.com/pool/main/o/openssl/openssl_1.1.1f-1ubuntu2_arm64.deb

# Extract binaries and libs
RUN cd /tmp/debs && for deb in *.deb; do dpkg-deb -x "$deb" /tmp/extracted; done

RUN cp /tmp/extracted/sbin/wpa_supplicant /output/ && \
    cp /tmp/extracted/sbin/wpa_cli /output/ && \
    cp /tmp/extracted/usr/bin/wpa_passphrase /output/ && \
    cp /tmp/extracted/usr/bin/openssl /output/

# Copy only the versioned lib files (not symlinks) + create soname symlinks
RUN cd /tmp/extracted && \
    find . -name "afalg.so" -exec cp {} /output/libs/ \; && \
    find . -name "capi.so" -exec cp {} /output/libs/ \; && \
    find . -name "padlock.so" -exec cp {} /output/libs/ \; && \
    find . -type f -name "libcrypto.so.1.1" -exec cp {} /output/libs/ \; && \
    find . -type f -name "libssl.so.1.1" -exec cp {} /output/libs/ \; && \
    find . -type f -name "libnl-3.so.200.26.0" -exec cp {} /output/libs/ \; && \
    find . -type f -name "libnl-genl-3.so.200.26.0" -exec cp {} /output/libs/ \; && \
    find . -type f -name "libnl-route-3.so.200.26.0" -exec cp {} /output/libs/ \; && \
    find . -type f -name "libdbus-1.so.3.19.11" -exec cp {} /output/libs/ \; && \
    find . -type f -name "libpcsclite.so.1.0.0" -exec cp {} /output/libs/ \; && \
    find . -type f -name "libsystemd.so.0.28.0" -exec cp {} /output/libs/ \; && \
    find . -type f -name "liblzma.so.5.2.4" -exec cp {} /output/libs/ \; && \
    find . -type f -name "liblz4.so.1.9.2" -exec cp {} /output/libs/ \; && \
    find . -type f -name "libgcrypt.so.20.2.5" -exec cp {} /output/libs/ \; && \
    find . -type f -name "libgpg-error.so.0.28.0" -exec cp {} /output/libs/ \;

# Soname symlinks (sonames from DT_SONAME of each lib)
RUN cd /output/libs && \
    ln -s libnl-3.so.200.26.0 libnl-3.so.200 && \
    ln -s libnl-genl-3.so.200.26.0 libnl-genl-3.so.200 && \
    ln -s libnl-route-3.so.200.26.0 libnl-route-3.so.200 && \
    ln -s libdbus-1.so.3.19.11 libdbus-1.so.3 && \
    ln -s libpcsclite.so.1.0.0 libpcsclite.so.1 && \
    ln -s libsystemd.so.0.28.0 libsystemd.so.0 && \
    ln -s liblzma.so.5.2.4 liblzma.so.5 && \
    ln -s liblz4.so.1.9.2 liblz4.so.1 && \
    ln -s libgcrypt.so.20.2.5 libgcrypt.so.20 && \
    ln -s libgpg-error.so.0.28.0 libgpg-error.so.0

# Copy kernel modules
RUN cp /opt/linux-5.10/drivers/net/wireless/mediatek/mt76/mt76.ko /output/ && \
    cp /opt/linux-5.10/drivers/net/wireless/mediatek/mt76/mt76-usb.ko /output/ && \
    cp /opt/linux-5.10/drivers/net/wireless/mediatek/mt76/mt76x02-lib.ko /output/ && \
    cp /opt/linux-5.10/drivers/net/wireless/mediatek/mt76/mt76x02-usb.ko /output/ && \
    cp /opt/linux-5.10/drivers/net/wireless/mediatek/mt76/mt76x0/mt76x0-common.ko /output/ && \
    cp /opt/linux-5.10/drivers/net/wireless/mediatek/mt76/mt76x0/mt76x0u.ko /output/

# Generate modalias patterns from all .ko modules (for S99zwifi USB device detection)
RUN for ko in /output/*.ko; do \
        modinfo "$ko" 2>/dev/null | grep "^alias:.*usb:" | sed 's/alias: *//' | sed 's/d\*dc.*//'; \
    done | sort -u > /output/modinfo_aliases.txt

# Copy scripts
COPY scripts/ /output/scripts/
RUN cp /output/scripts/*.sh /output/ && \
    cp /output/scripts/*.php /output/ && \
    cp /output/scripts/S99zwifi /output/ && \
    rm -rf /output/scripts

# Verify modules
RUN modinfo /output/mt76x0u.ko | grep -E "vermagic|filename"

# Create ZIP
RUN cd /output && zip -X --symlinks -r /wifi_addon.zip . && \
    sha256sum /wifi_addon.zip && \
    echo "=== ZIP contents ===" && \
    unzip -l /wifi_addon.zip
