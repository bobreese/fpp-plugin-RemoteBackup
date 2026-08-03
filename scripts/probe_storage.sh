#!/bin/bash
# Probe local block devices and classify them as candidates for the
# Remote Backup destination: NVMe, SSD, USB flash, or the SD
# card / root filesystem as a last-resort fallback. Also reports any
# USB devices that are present but not currently mounted, so the UI can
# offer to mount them.
#
# Output: single JSON object on stdout:
# {
#   "nvme":   [ {path,mountpoint,fstype,sizeBytes,availBytes,label}, ... ],
#   "ssd":    [ ... ],
#   "usb":    [ ... ],
#   "sdcard": [ ... ],   // root filesystem + any other mmcblk mounts
#   "usbUnmounted": [ {path,fstype,uuid,label,sizeBytes,hasFilesystem}, ... ],
#   "hasPreferred": true|false   // true if any nvme/ssd candidate exists
# }

set -o pipefail
. "$(dirname "$0")/lib_common.sh"

TMP_JSON=$(mktemp "${DATA_DIR}/tmp_storage_XXXXXX.json")
trap 'rm -f "$TMP_JSON"' EXIT

ROOT_SRC=$(findmnt -n -o SOURCE / 2>/dev/null || echo "")
ROOT_DISK=$(lsblk -no PKNAME "$ROOT_SRC" 2>/dev/null || echo "")

LSBLK_JSON=$(lsblk -J -b -o NAME,KNAME,PKNAME,TYPE,TRAN,ROTA,SIZE,MOUNTPOINT,FSTYPE,PATH,UUID,LABEL 2>/dev/null)
if [ -z "$LSBLK_JSON" ]; then
    echo '{"nvme":[],"ssd":[],"usb":[],"sdcard":[],"usbUnmounted":[],"hasPreferred":false,"error":"lsblk failed"}'
    exit 0
fi

echo "$LSBLK_JSON" | jq --arg rootdisk "$ROOT_DISK" '
  def flatten_devs:
    [.blockdevices[] | recurse(.children[]?) ] ;

  (flatten_devs) as $devs
  | ($devs | map(select(.mountpoint != null and .mountpoint != "" and (.fstype != null))
      | select(.mountpoint | test("^/(proc|sys|dev|run)") | not))) as $mounted
  | ($devs | map(select((.type == "part" or .type == "disk")
      and (.mountpoint == null or .mountpoint == "")
      and .tran == "usb"))) as $usb_unmounted
  | {
      nvme:   [ $mounted[] | select(.tran == "nvme") ],
      ssd:    [ $mounted[] | select((.tran == "sata" or .tran == "ata") and (.rota == false or .rota == "0" or .rota == 0)) ],
      usb:    [ $mounted[] | select(.tran == "usb") ],
      sdcard: [ $mounted[] | select((.pkname == $rootdisk) or (.name == $rootdisk) or (.mountpoint == "/")) ],
      usbUnmounted: [ $usb_unmounted[] | {
          path: .path, kname: .kname, fstype: .fstype, uuid: .uuid, label: .label,
          sizeBytes: .size, hasFilesystem: (.fstype != null and .fstype != "")
      } ]
    }
' > "$TMP_JSON"

# Second pass: attach available bytes via df, dedupe, and build final doc
RESULT=$(jq -c '
  def dedupe: unique_by(.mountpoint);
  {
    nvme: (.nvme | dedupe),
    ssd: (.ssd | dedupe),
    usb: (.usb | dedupe),
    sdcard: (.sdcard | dedupe),
    usbUnmounted: (.usbUnmounted | unique_by(.path))
  }
' "$TMP_JSON")

# Enrich each mounted entry with availBytes/sizeBytes via df (jq can't shell out)
enrich() {
    local arr_json="$1"
    echo "$arr_json" | jq -c '.[]' | while read -r item; do
        mp=$(echo "$item" | jq -r '.mountpoint')
        avail=$(df -B1 --output=avail "$mp" 2>/dev/null | tail -1 | tr -d ' ')
        size=$(df -B1 --output=size "$mp" 2>/dev/null | tail -1 | tr -d ' ')
        [ -z "$avail" ] && avail=0
        [ -z "$size" ] && size=0
        label=$(echo "$item" | jq -r '.path // .name')
        echo "$item" | jq -c --argjson avail "$avail" --argjson size "$size" --arg label "$label" \
            '. + {availBytes: $avail, sizeBytesDf: $size, deviceLabel: $label}'
    done | jq -s '.'
}

NVME=$(enrich "$(echo "$RESULT" | jq -c '.nvme')")
SSD=$(enrich "$(echo "$RESULT" | jq -c '.ssd')")
USB=$(enrich "$(echo "$RESULT" | jq -c '.usb')")
SDCARD=$(enrich "$(echo "$RESULT" | jq -c '.sdcard')")
USB_UNMOUNTED=$(echo "$RESULT" | jq -c '.usbUnmounted')

HAS_PREFERRED=false
if [ "$(echo "$NVME" | jq 'length')" -gt 0 ] || [ "$(echo "$SSD" | jq 'length')" -gt 0 ]; then
    HAS_PREFERRED=true
fi

jq -n --argjson nvme "$NVME" --argjson ssd "$SSD" --argjson usb "$USB" --argjson sdcard "$SDCARD" \
      --argjson usbUnmounted "$USB_UNMOUNTED" --argjson pref "$HAS_PREFERRED" \
    '{nvme: $nvme, ssd: $ssd, usb: $usb, sdcard: $sdcard, usbUnmounted: $usbUnmounted, hasPreferred: $pref}'
