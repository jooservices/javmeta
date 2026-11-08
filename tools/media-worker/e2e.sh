#!/usr/bin/env bash
# Run with scripts/docker/runtime media bash tools/media-worker/e2e.sh.
set -euo pipefail
cd "$(dirname "$0")/../.."
mkdir -p .tmp
media_test_dir="$(mktemp -d "${PWD}/.tmp/media-e2e.XXXXXX")"
trap 'rm -rf -- "$media_test_dir"' EXIT
espeak-ng -w "${media_test_dir}/speech.wav" 'The red square moves across the screen. This is a local speech recognition test.'
ffmpeg -v error -f lavfi -i 'color=red:s=320x240:d=10' -f lavfi -i 'color=blue:s=320x240:d=10' -i "${media_test_dir}/speech.wav" -filter_complex '[0:v][1:v]concat=n=2:v=1:a=0[v]' -map '[v]' -map 2:a -af apad -t 20 -c:v libx264 -threads 1 -c:a aac "${media_test_dir}/generated.mp4"
python tools/media-worker/e2e.py "$media_test_dir"
