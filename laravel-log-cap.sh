#!/usr/bin/env bash
set -euo pipefail

log=/www/wwwroot/app.sushi.catering/storage/logs/laravel.log
max_bytes=5000000000
keep_bytes=4900000000
interval_seconds=60

[[ -f "$log" ]] || { echo "Log file missing: $log" >&2; exit 1; }

filesystem=$(findmnt -T "$log" -n -o FSTYPE)
[[ "$filesystem" == ext4 || "$filesystem" == xfs ]] || {
    echo "Unsupported filesystem: $filesystem; log was not changed" >&2
    exit 1
}

# Prevent two copies of this monitor from trimming the file together.
exec 9>/run/laravel-log-cap.lock
flock -n 9 || exit 0

while true; do
    [[ -f "$log" ]] || { echo "Log file missing: $log" >&2; exit 1; }
    size=$(stat -c %s -- "$log")

    if (( size > max_bytes )); then
        block=$(stat -f -c %S -- "$log")
        (( block > 0 )) || exit 1

        # Collapse whole filesystem blocks from the beginning, preserving the inode.
        excess=$((size - keep_bytes))
        remove=$(( (excess + block - 1) / block * block ))
        fallocate --collapse-range --offset 0 --length "$remove" -- "$log"

        printf '%s: trimmed %s to %s bytes\n' \
            "$(date -u +%FT%TZ)" "$size" "$(stat -c %s -- "$log")"
    fi

    sleep "$interval_seconds"
done