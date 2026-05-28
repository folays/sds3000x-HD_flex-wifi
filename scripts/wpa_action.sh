#!/bin/sh
case "$2" in
    CONNECTED)
        udhcpc -i "$1" -n -q
        ;;
    DISCONNECTED)
        ip addr flush dev "$1"
        ;;
esac
