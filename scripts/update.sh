#!/bin/sh
set -e

UPGRADE_DIR=/usr/bin/siglent/usr/usr/upgrade
FLEX_DIR=/usr/bin/siglent/usr/flex-wifi
LOG=/usr/bin/siglent/usr/flex-wifi_install.log

echo "=== flex-wifi addon install ===" | tee $LOG

# 1. Rootfs: S99zwifi + firmware symlink
mount -o remount,rw /
cp -pf $UPGRADE_DIR/S99zwifi /etc/init.d/S99zwifi
chown 0:0 /etc/init.d/S99zwifi
chmod 755 /etc/init.d/S99zwifi
mkdir -p /lib/firmware/mediatek
ln -sf $FLEX_DIR/mt7610u.bin /lib/firmware/mediatek/mt7610u.bin
sync
mount -o remount,ro /
echo "rootfs: S99zwifi + firmware symlink done" | tee -a $LOG

# 2. flex-wifi directory on writable partition (clean install)
rm -rf $FLEX_DIR/
mkdir -p $FLEX_DIR/libs
cp -pf $UPGRADE_DIR/mt76*.ko $FLEX_DIR/
cp -pf $UPGRADE_DIR/mt7610u.bin $FLEX_DIR/
cp -pf $UPGRADE_DIR/flex-wifi.php $FLEX_DIR/
cp -pf $UPGRADE_DIR/setup.sh $FLEX_DIR/
cp -pf $UPGRADE_DIR/wpa_* $FLEX_DIR/
cp -pf $UPGRADE_DIR/libs/* $FLEX_DIR/libs/
cp -pf $UPGRADE_DIR/openssl $FLEX_DIR/
cp -pf $UPGRADE_DIR/modinfo_aliases.txt $FLEX_DIR/
chown -R 0:0 $FLEX_DIR
chmod 755 $FLEX_DIR/setup.sh $FLEX_DIR/wpa_supplicant $FLEX_DIR/wpa_cli $FLEX_DIR/wpa_passphrase $FLEX_DIR/wpa_action.sh $FLEX_DIR/openssl

echo "flex-wifi files copied" | tee -a $LOG

echo "=== flex-wifi addon install complete ===" | tee -a $LOG
