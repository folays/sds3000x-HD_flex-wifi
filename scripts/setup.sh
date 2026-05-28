#!/bin/sh

FLEX_DIR=/usr/bin/siglent/usr/flex-wifi

# Load WiFi kernel modules
insmod $FLEX_DIR/mt76.ko
insmod $FLEX_DIR/mt76-usb.ko
insmod $FLEX_DIR/mt76x02-lib.ko
insmod $FLEX_DIR/mt76x02-usb.ko
insmod $FLEX_DIR/mt76x0-common.ko
insmod $FLEX_DIR/mt76x0u.ko

# Symlink flex-wifi.php into /tmp/ so lighttpd serves it via web_img
ln -sf $FLEX_DIR/flex-wifi.php /tmp/flex-wifi.php

# If wifi config exists, connect
if [ -f /usr/bin/siglent/usr/flex-wifi.conf ]; then
    export LD_LIBRARY_PATH=$FLEX_DIR/libs:$LD_LIBRARY_PATH

    # Wait for wlan0 to appear
    for i in 1 2 3 4 5; do
        [ -d /sys/class/net/wlan0 ] && break
        sleep 1
    done

    if [ -d /sys/class/net/wlan0 ]; then
        ip link set wlan0 up
        $FLEX_DIR/wpa_supplicant -Dnl80211 -iwlan0 -c/usr/bin/siglent/usr/flex-wifi.conf -C/tmp/wpa_ctrl -B
        $FLEX_DIR/wpa_cli -p/tmp/wpa_ctrl -a $FLEX_DIR/wpa_action.sh -B
    fi
fi
