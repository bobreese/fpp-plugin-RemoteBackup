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
  # lsblk only populates TRAN (and sometimes ROTA) on whole-disk rows,
  # not on partition rows - a partition just reads back null for both.
  # NVMe/SD-card root filesystems are always partitions (unlike the
  # USB flow in this plugin, which formats/mounts the raw whole disk), so
  # without this resolution step .tran=="nvme" never matches a real
  # NVMe root partition at all, and it falls through to the SD Card
  # fallback bucket by mistake - it never even reaches the NVMe bucket.
  | ($devs | map(select(.type == "disk")) | map({(.name): {tran: .tran, rota: .rota}}) | add // {}) as $disktran
  | ($devs | map(
      . as $d
      | ($disktran[$d.pkname // $d.name] // {tran: null, rota: null}) as $parent
      | $d + {tran: ($d.tran // $parent.tran), rota: ($d.rota // $parent.rota)}
    )) as $devs_r

  | def is_nvme: .tran == "nvme";
    def is_ssd: (.tran == "sata" or .tran == "ata") and (.rota == false or .rota == "0" or .rota == 0);
    def is_usb: .tran == "usb";
    def is_rootdisk: (.pkname == $rootdisk) or (.name == $rootdisk) or (.mountpoint == "/");

    ($devs_r | map(select(.mountpoint != null and .mountpoint != "" and (.fstype != null))
        | select(.mountpoint | test("^/(proc|sys|dev|run)") | not))) as $mounted
  # Whole disks that already have at least one partition (e.g. a drive
  # this plugin previously formatted with the GPT-partition fix) should
  # only be represented by their partition(s) here, not ALSO by the raw
  # disk row itself - otherwise an already-good, ready-to-mount drive
  # shows up a second time as a confusing "no filesystem - needs
  # formatting" entry for its own parent disk.
  | ($devs_r | map(select(.type == "part")) | map(.pkname // .name)) as $disks_with_parts
  | ($devs_r | map(select(
      ((.type == "part") or ((.type == "disk") and ((.name as $n | $disks_with_parts | index($n)) == null)))
      and (.mountpoint == null or .mountpoint == "")
      and is_usb))) as $usb_unmounted
  | {
      nvme:   [ $mounted[] | select(is_nvme) ],
      ssd:    [ $mounted[] | select(is_ssd) ],
      usb:    [ $mounted[] | select(is_usb) ],
      # Fallback bucket for whatever the OS root sits on when it is NOT
      # already covered by one of the preferred/USB categories above -
      # e.g. a real SD card or eMMC. Without excluding is_nvme/is_ssd/
      # is_usb here, an NVMe- or USB-SSD-booted root would match
      # is_rootdisk too and get listed a second time, mislabeled as
      # "SD Card / System Storage".
      sdcard: [ $mounted[] | select(is_rootdisk and (is_nvme or is_ssd or is_usb | not)) ],
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
        devlabel=$(echo "$item" | jq -r '.path // .name')
        # NOTE: the --arg name can't be "label" - jq >=1.6 reserves that
        # identifier for its `label $out | ... break $out` control-flow
        # syntax and will fail to compile the filter below with it.
        echo "$item" | jq -c --argjson avail "$avail" --argjson size "$size" --arg devlabel "$devlabel" \
            '. + {availBytes: $avail, sizeBytesDf: $size, deviceLabel: $devlabel}'
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
